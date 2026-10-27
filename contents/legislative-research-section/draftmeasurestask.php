<?php
// START OF THE FIXED PHP BLOCK
session_start();
require_once __DIR__ . '/../../auth.php';
require_role(['research', 'researchstaff', 'superadmin']); // same roles the sidebar shows this page to
require_once 'connection.php'; // Includes the lgu2 database connection
require_once __DIR__ . '/../../includes/audit_log.php'; // Audit log system

// API key shared with RACS (.env)
$api_key = (string) env('LGU2_API_KEY', '');

// Intelligent category detection function
function detectMeasureCategory($title, $content) {
    $text = strtolower($title . ' ' . $content);
    
    // Define keywords for each category
    $categoryKeywords = [
        'Agriculture' => ['agriculture', 'farming', 'farm', 'crop', 'livestock', 'fishery', 'fisheries', 'aquaculture', 'irrigation', 'harvest', 'plantation', 'agri', 'rice', 'corn', 'vegetable', 'fruit', 'poultry', 'cattle', 'swine', 'agricultural'],
        'Education' => ['education', 'school', 'student', 'teacher', 'learning', 'scholarship', 'university', 'college', 'classroom', 'curriculum', 'academic', 'educational', 'training', 'seminar', 'workshop', 'literacy', 'kindergarten', 'elementary', 'secondary', 'tertiary'],
        'Health' => ['health', 'hospital', 'medical', 'clinic', 'doctor', 'nurse', 'patient', 'medicine', 'healthcare', 'sanitation', 'hygiene', 'disease', 'vaccination', 'immunization', 'wellness', 'nutrition', 'mental health', 'dental', 'pharmacy', 'ambulance'],
        'Infrastructure' => ['infrastructure', 'road', 'bridge', 'building', 'construction', 'facility', 'public works', 'highway', 'street', 'pavement', 'drainage', 'water system', 'sewage', 'utilities', 'structure', 'renovation', 'repair', 'maintenance', 'engineering'],
        'Environment' => ['environment', 'environmental', 'pollution', 'waste', 'garbage', 'recycling', 'climate', 'conservation', 'ecology', 'green', 'sustainable', 'biodiversity', 'forest', 'tree', 'reforestation', 'clean', 'air quality', 'water quality', 'ecosystem'],
        'Social Services' => ['social', 'welfare', 'community', 'senior citizen', 'elderly', 'youth', 'children', 'family', 'assistance', 'aid', 'support', 'charity', 'indigent', 'poverty', 'housing', 'shelter', 'livelihood', 'employment', 'pwd', 'disability'],
        'Economic Development' => ['economic', 'business', 'commerce', 'trade', 'industry', 'investment', 'entrepreneur', 'market', 'tourism', 'development', 'growth', 'revenue', 'income', 'job creation', 'msme', 'cooperative', 'enterprise'],
        'Public Safety' => ['safety', 'security', 'police', 'fire', 'emergency', 'disaster', 'rescue', 'protection', 'crime', 'law enforcement', 'traffic', 'accident', 'prevention', 'response', 'preparedness', 'risk', 'hazard', 'calamity', 'peace and order'],
        'Transportation' => ['transport', 'transportation', 'vehicle', 'traffic', 'parking', 'terminal', 'jeepney', 'tricycle', 'bus', 'public transport', 'commute', 'mobility', 'transit', 'route', 'fare'],
        'Finance' => ['finance', 'financial', 'budget', 'fund', 'tax', 'revenue', 'expenditure', 'appropriation', 'fiscal', 'accounting', 'audit', 'treasury', 'payment', 'collection', 'fee', 'charge', 'subsidy', 'grant'],
    ];
    
    $categoryScores = [];
    
    foreach ($categoryKeywords as $category => $keywords) {
        $score = 0;
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                $score++;
            }
        }
        $categoryScores[$category] = $score;
    }
    
    // Get category with highest score
    $maxScore = max($categoryScores);
    if ($maxScore > 0) {
        return array_search($maxScore, $categoryScores);
    }
    
    return 'Others';
}

// Pagination and search settings
$records_per_page = 5;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$search_query = htmlspecialchars($_GET['search'] ?? '');
$category_filter = htmlspecialchars($_GET['category'] ?? '');
$offset = ($current_page - 1) * $records_per_page;

// Handle retrieve action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['retrieve_task'])) {
    $m9_SC_ID = isset($_POST['m9_SC_ID']) ? (int)$_POST['m9_SC_ID'] : 0;
    
    if ($m9_SC_ID > 0) {
        try {
            $lgu2_conn->begin_transaction();
            
            // Update measure status back to Draft
            $sql_update = "UPDATE m9_similaritychecking SET measure_status = 'Draft' WHERE m9_SC_ID = ?";
            $stmt_update = $lgu2_conn->prepare($sql_update);
            
            if ($stmt_update === false) {
                throw new Exception('Error preparing UPDATE statement: ' . $lgu2_conn->error);
            }
            
            $stmt_update->bind_param("i", $m9_SC_ID);
            
            if (!$stmt_update->execute()) {
                throw new Exception('Error executing UPDATE statement: ' . $stmt_update->error);
            }
            
            $stmt_update->close();
            $lgu2_conn->commit();
            
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Measure successfully retrieved and moved back to Incoming Task!'];
            
        } catch (Exception $e) {
            $lgu2_conn->rollback();
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to retrieve measure. Error: ' . $e->getMessage()];
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=pending-measures");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_task'])) {
    
    // Sanitize and retrieve the form data
    $m9_SC_ID = isset($_POST['m9_SC_ID']) ? (int)$_POST['m9_SC_ID'] : 0;
    $m9_SC_Code = isset($_POST['m9_SC_Code']) ? htmlspecialchars($_POST['m9_SC_Code']) : '';
    $m1_MC_ID = isset($_POST['m1_MC_ID']) ? (int)$_POST['m1_MC_ID'] : 0;
    $m1_MC_code = isset($_POST['m1_MC_code']) ? htmlspecialchars($_POST['m1_MC_code']) : '';
    $date_created = isset($_POST['date_created']) ? htmlspecialchars($_POST['date_created']) : '';
    $measure_type = isset($_POST['measure_type']) ? htmlspecialchars($_POST['measure_type']) : '';
    $title = isset($_POST['measure_title']) ? htmlspecialchars($_POST['measure_title']) : '';
    $content = isset($_POST['measure_content']) ? htmlspecialchars($_POST['measure_content']) : '';
    $introducers = isset($_POST['introducers']) ? htmlspecialchars($_POST['introducers']) : '';
    
    // Set the measure_status to 'In Review for Docketing'
    $measure_status = 'In Review for Docketing';
    
    $checking_remarks_pending = isset($_POST['checking_remarks_pending']) ? htmlspecialchars($_POST['checking_remarks_pending']) : '';
    $checking_remarks_approved = isset($_POST['checking_remarks_approved']) ? htmlspecialchars($_POST['checking_remarks_approved']) : '';
    $checking_notes = isset($_POST['checking_notes']) ? htmlspecialchars($_POST['checking_notes']) : '';
    $checked_by = isset($_POST['checked_by']) ? htmlspecialchars($_POST['checked_by']) : '';
    $datetime_submitted = isset($_POST['datetime_submitted']) ? htmlspecialchars($_POST['datetime_submitted']) : '';
    $category = isset($_POST['category']) ? htmlspecialchars($_POST['category']) : '';
    $sp_no = isset($_POST['sp_no']) ? htmlspecialchars($_POST['sp_no']) : '';
    $year_cc = isset($_POST['year_cc']) ? htmlspecialchars($_POST['year_cc']) : '';

    try {
        // Start a transaction for safe database operation
        $lgu2_conn->begin_transaction();

                // START OF NEW CODE BLOCK
        $sqlFetchMC = "SELECT m1_MC_ID, m1_MC_code FROM m9_similaritychecking WHERE m9_SC_ID = ?";
        $stmtFetchMC = $lgu2_conn->prepare($sqlFetchMC);
        if ($stmtFetchMC === false) {
            throw new Exception('Error preparing SELECT statement: ' . $lgu2_conn->error);
        }
        $stmtFetchMC->bind_param("i", $m9_SC_ID);
        $stmtFetchMC->execute();
        $result = $stmtFetchMC->get_result();
        $dataFromDb = $result->fetch_assoc();
        $stmtFetchMC->close();

        if ($dataFromDb) {
            $m1_MC_ID = (int)$dataFromDb['m1_MC_ID'];
            $m1_MC_code = htmlspecialchars($dataFromDb['m1_MC_code']);
        } else {
            // Handle case where no record is found
            $m1_MC_ID = 0;
            $m1_MC_code = '';
        }
    
        // 1. Update the `m9_similaritychecking` table with the new status
        $sqlUpdateSc = "UPDATE m9_similaritychecking SET measure_status = ?, checking_remarks_pending = ?, checking_remarks_approved = ?, checking_notes = ?, checked_by = ?, datetime_submitted = ? WHERE m9_SC_ID = ?";
        $stmtUpdateSc = $lgu2_conn->prepare($sqlUpdateSc);
        if ($stmtUpdateSc === false) {
            throw new Exception('Error preparing UPDATE m9_similaritychecking statement: ' . $lgu2_conn->error);
        }
        $stmtUpdateSc->bind_param("ssssssi", $measure_status, $checking_remarks_pending, $checking_remarks_approved, $checking_notes, $checked_by, $datetime_submitted, $m9_SC_ID);
        if (!$stmtUpdateSc->execute()) {
            throw new Exception('Error executing UPDATE m9_similaritychecking statement: ' . $stmtUpdateSc->error);
        }
        $stmtUpdateSc->close();

        // Log measure submission to audit log
        $auditConn = db_connect();
        if (!$auditConn->connect_error) {
            logActivity('submit', "Measure '{$title}' submitted for review (ID: {$m9_SC_ID})", $auditConn);
            $auditConn->close();
        }
        

        // --- API SENDING LOGIC STARTS HERE ---
        // Create the payload with the updated status
        $api_url = env('RACS_RECEIVE_URL', 'https://racs.jampzdev.com/receivers/receive_from_lgu2.php');
        $payload = [
            'api_key' => $api_key,
            'm9_SC_ID' => $m9_SC_ID,
            'm9_SC_Code' => $m9_SC_Code,
            'm1_MC_ID' => $m1_MC_ID,
            'm1_MC_code' => $m1_MC_code,
            'date_created' => $date_created,
            'measure_type' => $measure_type,
            'measure_title' => $title,
            'measure_content' => $content,
            'introducers' => $introducers,
            'measure_status' => $measure_status, // Use the new 'In Review for Docketing' status
            'checking_remarks_pending' => $checking_remarks_pending,
            'checking_remarks_approved' => $checking_remarks_approved,
            'checking_notes' => $checking_notes,
            'checked_by' => $checked_by,
            'datetime_submitted' => $datetime_submitted,
            'category' => $category,
            'sp_no' => $sp_no,
            'year_cc' => $year_cc
        ];

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($payload))
        ]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_POSTREDIR, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $api_message = '';
        if ($curl_error) {
            throw new Exception("API Send Error: " . $curl_error);
        } else {
            $api_result = json_decode($response, true);
            if ($api_result === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("API call failed with HTTP code {$http_code}. Response: " . htmlspecialchars($response));
            } elseif (!isset($api_result['success']) || !$api_result['success']) {
                throw new Exception("API call failed: " . ($api_result['message'] ?? 'Unknown error'));
            } else {
                $api_message = "API call successful: " . $api_result['message'];
            }
        }

        // If the database operation AND API call are successful, commit the changes
        $lgu2_conn->commit();
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Task successfully updated and sent to the other system! ' . $api_message];

    } catch (Exception $e) {
        // An error occurred (either DB or API), roll back the transaction
        $lgu2_conn->rollback();
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Process failed and changes were rolled back. Error: ' . $e->getMessage()];
    } finally {
        if (isset($lgu2_conn)) {
            $lgu2_conn->close();
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

$pageTitle = "Draft Measures Task";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - LGU Research System</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= e(url('assets/img/LGU2.png')) ?>">
    <link rel="shortcut icon" type="image/png" href="<?= e(url('assets/img/LGU2.png')) ?>">
    
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


/* General Body and Container */
body {
    background-color: #f0f4f7; /* Light gray-blue, common in government sites */
    font-family: Montserrat, system-ui, -apple-system, Arial, Helvetica, sans-serif;
    color: #333; /* Dark gray for text */
    line-height: 1.6;
}

.container {
    margin-top: 20px;
    padding: 2rem;
    background-color: #fff;
    border-radius: 0; /* Remove rounded corners for a sharper, more official look */
    box-shadow: 0 4px 6px rgba(0,0,0,.1); /* A subtle shadow */
}

/* Headings and Links */
h2, h3, h4 {
    color: #004a8b; /* A deep, professional blue */
    font-weight: 600;
}

a {
    color: #004a8b;
    text-decoration: none; /* No underline by default */
}

a:hover {
    text-decoration: underline; /* Underline on hover for clarity */
}

/* Modern Tabs Navigation */
.nav-tabs {
    border-bottom: none;
    background: linear-gradient(135deg, rgba(17, 82, 114, 0.1) 0%, rgba(0, 74, 139, 0.05) 100%);
    backdrop-filter: blur(10px);
    border-radius: 12px 12px 0 0;
    padding: 8px 8px 0 8px;
    margin-bottom: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.nav-tabs .nav-item {
    margin-right: 4px;
}

.nav-tabs .nav-item:last-child {
    margin-right: 0;
}

.nav-tabs .nav-link {
    border: none;
    background: rgba(255, 255, 255, 0.7);
    color: #084157;
    font-weight: 500;
    font-size: 0.9rem;
    padding: 14px 20px;
    margin: 0;
    border-radius: 8px 8px 0 0;
    transition: background-color 0.2s ease;
    white-space: nowrap;
    min-width: fit-content;
}

.nav-tabs .nav-link:hover {
    background: rgba(255, 255, 255, 0.9);
    color: #004a8b;
}

.nav-tabs .nav-link.active {
    background: #004a8b;
    color: #fff;
    font-weight: 600;
    border-bottom: 3px solid #0b3d6d;
}

/* Responsive tabs for mobile */
@media (max-width: 768px) {
    .nav-tabs {
        padding: 6px 6px 0 6px;
        flex-wrap: nowrap;
        overflow-x: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    
    .nav-tabs::-webkit-scrollbar {
        display: none;
    }
    
    .nav-tabs .nav-item {
        flex-shrink: 0;
        margin-right: 3px;
    }
    
    .nav-tabs .nav-link {
        font-size: 0.85rem;
        padding: 12px 16px;
        min-width: auto;
    }
}

/* Tab content styling */
.tab-content {
    background: #ffffff;
    border-radius: 0 0 12px 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    border-top: 1px solid rgba(17, 82, 114, 0.1);
}

.tab-pane {
    /* Removed animation for better performance */
}

/* Modern Card Styling */
.card {
    border: none;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    padding: 0 !important;
}

/* Modern Headings */
.tab-pane h2 {
    font-size: 1.4rem;
    font-weight: 600;
    color: #084157;
    margin: 0 0 1.5rem 0 !important;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid rgba(0, 74, 139, 0.1);
    position: relative;
    display: inline-block;
}

.tab-pane h2::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 100%;
    height: 2px;
    background: linear-gradient(135deg, #004a8b 0%, #115272 100%);
    border-radius: 1px;
}

.table th, .table td {
    padding: 0.65rem;
    vertical-align: middle; /* Aligns content vertically */
    border-top: 1px solid #e9ecef;
}

/* Modern Glass Search Form */
.tab-pane .input-group {
    max-width: 400px;
    margin-bottom: 1.5rem;
    margin-left: auto;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.25) 0%, rgba(255, 255, 255, 0.1) 100%);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(17, 82, 114, 0.2);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.tab-pane .form-control {
    border: none;
    border-radius: 0;
    padding: 0.75rem 1.2rem;
    font-size: 0.9rem !important;
    background: transparent;
    color: #084157;
    font-weight: 500;
    transition: all 0.3s ease;
    backdrop-filter: blur(5px);
}

.tab-pane .form-control::placeholder {
    color: rgba(8, 65, 87, 0.7);
    font-weight: 400;
}

.tab-pane .form-control:focus {
    border: none;
    box-shadow: none;
    background: rgba(255, 255, 255, 0.2);
    color: #084157;
    outline: none;
}

.tab-pane .btn-outline-secondary {
    border: none;
    background: rgba(0, 74, 139, 0.15);
    backdrop-filter: blur(5px);
    color: #004a8b;
    border-radius: 0;
    padding: 0.75rem 1.4rem;
    font-size: 0.80rem !important;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border-left: 1px solid rgba(17, 82, 114, 0.2);
}

.tab-pane .btn-outline-secondary:hover {
    background: linear-gradient(135deg, rgba(0, 74, 139, 0.8) 0%, rgba(17, 82, 114, 0.9) 100%);
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(0, 74, 139, 0.25);
}

.tab-pane .btn-outline-danger {
    border: none;
    background: rgba(220, 53, 69, 0.15);
    backdrop-filter: blur(5px);
    color: #dc3545;
    border-radius: 0;
    padding: 0.75rem 1.4rem;
    font-size: 0.80rem !important;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border-left: 1px solid rgba(220, 53, 69, 0.2);
}

.tab-pane .btn-outline-danger:hover {
    background: linear-gradient(135deg, rgba(220, 53, 69, 0.8) 0%, rgba(200, 35, 51, 0.9) 100%);
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(220, 53, 69, 0.25);
}

/* Glass search form responsive design */
@media (max-width: 768px) {
    .tab-pane .input-group {
        max-width: 100%;
        margin-left: 0;
        margin-right: 0;
    }
    
    .tab-pane .form-control {
        padding: 0.65rem 1rem;
        font-size: 0.85rem;
    }
    
    .tab-pane .btn-outline-secondary,
    .tab-pane .btn-outline-danger {
        padding: 0.65rem 1rem;
        font-size: 0.85rem;
    }
}

/* Enhanced glass effect on focus */
.tab-pane .input-group:focus-within {
    box-shadow: 0 8px 32px rgba(0, 74, 139, 0.2);
    border-color: rgba(0, 74, 139, 0.4);
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0.15) 100%);
}

/* Compact Container Layout */
.container {
    padding: 1rem !important;
    max-width: 100%;
}

/* Modern Clean Table Styling */
.table-responsive {
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    background: white;
    border: 1px solid rgba(17, 82, 114, 0.1);
}

.table {
    margin-bottom: 0;
    font-size: 0.9rem;
    border-collapse: separate;
    border-spacing: 0;
}

.table thead th {
    background: #004a8b !important;
    color: #ffffff !important;
    border: none;
    padding: 1rem 0.8rem;
    font-weight: 600 !important;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    position: relative;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* Removed the messy gradient line under header */

.table tbody tr {
    transition: background-color 0.2s ease;
    background: #ffffff;
    border-bottom: 1px solid rgba(17, 82, 114, 0.08);
}

.table tbody tr:nth-child(even) {
    background-color: rgba(240, 244, 247, 0.3);
}

.table tbody tr:hover {
    background: rgba(0, 74, 139, 0.05) !important;
}

.table td {
    border: none;
    padding: 1rem 0.8rem;
    vertical-align: middle;
    color: #2c3e50;
    line-height: 1.4;
}

/* Modern Compact Action Buttons */
.table .btn {
    margin: 1px;
    min-width: 60px;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 4px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.2px;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
}

.btn-primary {
    background: linear-gradient(135deg, #004a8b 0%, #115272 100%);
    color: white;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #115272 0%, #0b3d6d 100%);
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 74, 139, 0.25);
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, #28a745 0%, #22c55e 100%);
    color: white;
}

.btn-success:hover {
    background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(34, 197, 94, 0.25);
    color: white;
}

/* Action Button Container */
.table td:last-child {
    text-align: center;
    white-space: nowrap;
    padding: 0.8rem 0.5rem;
}

/* Button Group Styling */
.btn-group-vertical .btn {
    display: inline-block !important;
    margin: 0 1px;
    vertical-align: middle;
}

/* Alternative: Horizontal button layout */
.table .action-buttons {
    display: flex;
    gap: 4px;
    justify-content: center;
    align-items: center;
    flex-wrap: nowrap;
}

/* Enhanced Column Styling for Better Readability */
.table th:nth-child(1),
.table td:nth-child(1) {
    min-width: 100px;
    text-align: center;
    font-weight: 600;
    color: #084157;
}

/* Title Column - Better Typography */
.table th:nth-child(2),
.table td:nth-child(2) {
    max-width: 250px;
    text-align: left;
    font-weight: 500;
    line-height: 1.3;
    word-wrap: break-word;
}

/* Introducers Column */
.table th:nth-child(3),
.table td:nth-child(3) {
    max-width: 180px;
    text-align: left;
    font-size: 0.85rem;
    color: #5a6c7d;
}

/* Measure Type & Status - Compact Badges */
.table th:nth-child(4),
.table td:nth-child(4),
.table th:nth-child(5),
.table td:nth-child(5) {
    text-align: center;
    font-weight: 500;
    min-width: 90px;
}

/* Date & Time Column */
.table th:nth-child(6),
.table td:nth-child(6) {
    min-width: 120px;
    text-align: center;
    font-size: 0.85rem;
    color: #6b7280;
}

/* Status Badge Styling */
.table td .badge {
    font-size: 0.7rem;
    padding: 0.35rem 0.6rem;
    border-radius: 12px;
    font-weight: 500;
}

/* Measure Type Styling */
.table td:nth-child(4) {
    text-transform: capitalize;
    font-weight: 600;
    color: #115272;
}

/* Mobile Responsive Table Improvements */
@media (max-width: 768px) {
    .table-responsive {
        border-radius: 8px;
    }
    
    .table {
        font-size: 0.8rem;
    }
    
    .table thead th {
        padding: 0.6rem 0.4rem;
        font-size: 0.7rem;
    }
    
    .table td {
        padding: 0.8rem 0.4rem;
    }
    
    .btn-sm {
        padding: 0.2rem 0.4rem;
        font-size: 0.7rem;
        min-width: 50px;
    }
    
    /* Stack buttons vertically on very small screens */
    @media (max-width: 576px) {
        .table .action-buttons {
            flex-direction: column;
            gap: 2px;
        }
        
        .table td:last-child {
            padding: 0.5rem 0.2rem;
        }
    }
}

/* Print Styles */
@media print {
    .table-responsive {
        box-shadow: none;
        border: 1px solid #ccc;
    }
    
    .table thead th {
        background: #f8f9fa !important;
        color: #333 !important;
    }
    
    .btn {
        display: none;
    }
}

/* Explicitly set header and data alignment for each column for clarity */
.table th:nth-child(2),
.table td:nth-child(2) {
    text-align: left; /* Aligns 'Title' header and content to the left */
}

.table th:nth-child(3),
.table td:nth-child(3) {
    text-align: left; /* Aligns 'Introducers' header and content to the left */
}

/* Ensure the 'Actions' column is always centered */
.table th:last-child,
.table td:last-child {
    text-align: center;
}

/* General table alignment to catch any unaligned elements */
.table {
    text-align: center;
}

.table-responsive {
    border: 1px solid #e0e0e0;
    border-radius: 0;
    margin-bottom: 2rem;
}

.table {
    width: 100%;
    margin-bottom: 1rem;
    background-color: transparent;
    border-collapse: collapse;
}

.table thead th {
    background-color: #004a8b;
    color: #fff;
    border-bottom: 2px solid #004a8b;
    font-weight: 600;
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: #f9f9f9;
}

.table-hover tbody tr:hover {
    background-color: #eaf1f7;
}

.table td .btn-action {
    width: 75px !important;
    margin: 2px 0;
    border-radius: 0;
    font-size: 0.8rem;
    font-weight: 600;
}

/* Buttons and Forms */
.btn-primary {
    background-color: #004a8b;
    border-color: #004a8b;
    transition: background-color 0.3s;
}

.btn-primary:hover {
    background-color: #003866;
    border-color: #003866;
}

.btn-success {
    background-color: #28a745;
    border-color: #28a745;
    transition: background-color 0.3s;
}

.btn-success:hover {
    background-color: #218838;
    border-color: #1e7e34;
}

.btn-danger {
    background-color: #dc3545;
    border-color: #dc3545;
    transition: background-color 0.3s;
}

.btn-danger:hover {
    background-color: #c82333;
    border-color: #bd2130;
}

.btn-outline-secondary {
    color: #004a8b;
    border-color: #004a8b;
}

.btn-outline-secondary:hover {
    color: #fff;
    background-color: #004a8b;
    border-color: #004a8b;
}

.form-control {
    border-radius: 0;
    border: 1px solid #ccc;
    box-shadow: none;
}

/* Modern Pagination */
.pagination-container {
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, rgba(17, 82, 114, 0.05) 0%, rgba(0, 74, 139, 0.02) 100%);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(17, 82, 114, 0.15);
    border-radius: 12px;
    margin-top: 1.5rem;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.pagination-container > span {
    font-size: 0.95rem;
    font-weight: 600;
    color: #084157;
    background: rgba(255, 255, 255, 0.8);
    padding: 0.5rem 1rem;
    border-radius: 8px;
    border: 1px solid rgba(17, 82, 114, 0.1);
    backdrop-filter: blur(5px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.pagination {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.pagination .page-item {
    margin: 0;
}

.pagination .page-item .page-link {
    color: #004a8b;
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(17, 82, 114, 0.2);
    border-radius: 8px;
    padding: 0.65rem 1rem;
    font-weight: 500;
    font-size: 0.9rem;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    backdrop-filter: blur(5px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    height: 42px;
}

.pagination .page-item .page-link:hover {
    background: linear-gradient(135deg, rgba(0, 74, 139, 0.1) 0%, rgba(17, 82, 114, 0.05) 100%);
    color: #003866;
    border-color: rgba(0, 74, 139, 0.3);
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 74, 139, 0.15);
}

.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #004a8b 0%, #115272 100%);
    border-color: #004a8b;
    color: #ffffff;
    font-weight: 600;
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(0, 74, 139, 0.25);
}

.pagination .page-item.active .page-link:hover {
    background: linear-gradient(135deg, #115272 0%, #0b3d6d 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 74, 139, 0.3);
}

.pagination .page-item.disabled .page-link {
    color: #9ca3af;
    background: rgba(255, 255, 255, 0.4);
    border-color: rgba(17, 82, 114, 0.1);
    cursor: not-allowed;
    transform: none;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.pagination .page-item.disabled .page-link:hover {
    background: rgba(255, 255, 255, 0.4);
    color: #9ca3af;
    border-color: rgba(17, 82, 114, 0.1);
    transform: none;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

/* Navigation arrows styling */
.pagination .page-item:first-child .page-link,
.pagination .page-item:last-child .page-link {
    font-size: 1rem;
    font-weight: 600;
}

.pagination .page-item:first-child .page-link span,
.pagination .page-item:last-child .page-link span {
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Enhanced hover effects for navigation */
.pagination .page-item:not(.disabled):first-child .page-link:hover,
.pagination .page-item:not(.disabled):last-child .page-link:hover {
    background: linear-gradient(135deg, rgba(17, 82, 114, 0.1) 0%, rgba(0, 74, 139, 0.08) 100%);
}

/* Responsive pagination */
@media (max-width: 768px) {
    .pagination-container {
        padding: 1rem;
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
    }
    
    .pagination-container > span {
        font-size: 0.85rem;
        padding: 0.4rem 0.8rem;
        order: 2;
    }
    
    .pagination {
        order: 1;
        gap: 0.15rem;
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .pagination .page-item .page-link {
        padding: 0.5rem 0.7rem;
        font-size: 0.85rem;
        min-width: 36px;
        height: 36px;
    }
}

@media (max-width: 480px) {
    .pagination-container {
        padding: 0.75rem 0.5rem;
    }
    
    .pagination .page-item .page-link {
        padding: 0.4rem 0.6rem;
        font-size: 0.8rem;
        min-width: 32px;
        height: 32px;
    }
    
    .pagination-container > span {
        font-size: 0.8rem;
        padding: 0.35rem 0.7rem;
    }
}

/* Animation for page transitions */
@keyframes pageChange {
    0% {
        opacity: 0.7;
        transform: scale(0.95);
    }
    100% {
        opacity: 1;
        transform: scale(1);
    }
}

.pagination .page-item.active .page-link {
    animation: pageChange 0.3s ease-out;
}

/* Custom modal enhancements - works with Bootstrap modals */
.modal-body {
    padding: 1.5rem;
    max-height: 70vh;
    overflow-y: auto;
}

/* Enhanced Content Controls */
{{ ... }}
.measure-detail {
    margin-bottom: 1.5rem;
}

.measure-detail h3 {
    color: #004a8b;
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 2px solid #004a8b;
}

.title-header, .introducers-header, .measure-content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #dee2e6;
}

.content-controls, .introducers-controls, .title-controls {
    display: flex;
    gap: 0.5rem;
}

.content-control-btn, .introducers-expand-btn, .introducers-copy-btn, .title-copy-btn, .title-expand-btn {
    background: linear-gradient(135deg, #004a8b 0%, #003866 100%);
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 74, 139, 0.2);
    display: flex;
    align-items: center;
    gap: 0.4rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.content-control-btn:hover, .introducers-expand-btn:hover, .introducers-copy-btn:hover, .title-copy-btn:hover, .title-expand-btn:hover {
    background: linear-gradient(135deg, #003866 0%, #004a8b 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 74, 139, 0.3);
}

.title-copy-btn {
    padding: 8px 10px;
    min-width: 36px;
    height: 36px;
    justify-content: center;
}

/* Enhanced Content Areas */
.measure-content {
    background-color: #f8f9fa;
    padding: 1.2rem;
    border-radius: 8px;
    border-left: 4px solid #004a8b;
    white-space: pre-wrap;
    max-height: 400px;
    overflow-y: auto;
    line-height: 1.7;
    font-size: 0.95rem;
    color: #2c3e50;
    border: 1px solid #e9ecef;
    position: relative;
}

.measure-content::-webkit-scrollbar {
    width: 8px;
}

.measure-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.measure-content::-webkit-scrollbar-thumb {
    background: #004a8b;
    border-radius: 4px;
}

.measure-content::-webkit-scrollbar-thumb:hover {
    background: #003866;
}

.measure-content.expanded {
    max-height: 60vh;
}

/* Enhanced Title Styles */
#view-title {
    font-weight: 600;
    color: #1a365d;
    font-size: 1.05rem;
    line-height: 1.5;
    margin: 0;
    padding: 1rem;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    border-left: 4px solid #004a8b;
}

#view-title.expanded {
    max-height: none !important;
    overflow: visible !important;
}

/* Introducers Section */
.introducers-section {
    position: relative;
}

#view-introducers {
    line-height: 2.2;
    margin-top: 0.5rem;
    max-height: 120px;
    overflow: hidden;
    transition: max-height 0.2s ease;
    position: relative;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-left: 4px solid #004a8b;
    padding: 1rem;
    border-radius: 6px;
}

#view-introducers.expanded {
    max-height: none;
    overflow: visible;
}

.introducers-fade {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 40px;
    background: linear-gradient(transparent, rgba(248, 249, 250, 0.9));
    pointer-events: none;
    transition: opacity 0.2s ease;
}

#view-introducers.expanded .introducers-fade {
    opacity: 0;
}

.introducer-item {
    display: inline-block;
    background: #004a8b;
    color: white;
    padding: 0.4rem 0.8rem;
    margin: 0.2rem 0.3rem 0.2rem 0;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(0, 74, 139, 0.2);
}

.introducer-item:hover {
    background: #003866;
}

/* Introducer badges for send modal */
.introducer-badge-send {
    display: inline-block;
    background: #28a745;
    color: white;
    padding: 0.3rem 0.6rem;
    margin: 0.2rem 0.2rem 0.2rem 0;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
}

.introducer-badge-send:hover {
    background: #20893b;
}

/* Send Modal Controls */
.send-title-header, .send-introducers-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.send-introducers-controls, .send-title-controls {
    display: flex;
    gap: 0.5rem;
}

.send-title-copy-btn, .send-introducers-expand-btn, .send-introducers-copy-btn, .send-title-expand-btn {
    background: #28a745;
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.2s ease;
    box-shadow: 0 2px 8px rgba(40, 167, 69, 0.2);
    display: flex;
    align-items: center;
    gap: 0.4rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.send-title-copy-btn:hover, .send-introducers-expand-btn:hover, .send-introducers-copy-btn:hover, .send-title-expand-btn:hover {
    background: #20893b;
}

.send-title-copy-btn {
    padding: 8px 10px;
    min-width: 36px;
    height: 36px;
    justify-content: center;
}

/* Send modal expanded states */
#modal-send-title-text.expanded {
    max-height: none !important;
    overflow: visible !important;
}

#modal-send-introducers-text.expanded {
    max-height: none !important;
    overflow: visible !important;
}

.send-introducers-fade {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 40px;
    background: linear-gradient(transparent, rgba(248, 249, 250, 0.9));
    pointer-events: none;
    transition: opacity 0.3s ease;
    z-index: 1;
}

#modal-send-introducers-text.expanded .send-introducers-fade {
    opacity: 0 !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table td, .table th {
        font-size: 0.8rem;
    }
    
    .modal-content {
        margin: 10% auto;
        width: 95%;
    }
    
    .modal-body {
        padding: 1rem;
    }
    
    .content-controls, .introducers-controls {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .content-control-btn, .introducers-expand-btn, .introducers-copy-btn {
        font-size: 0.7rem;
        padding: 0.4rem 0.8rem;
    }
}

/* === SKELETON LOADING STYLES === */
.skeleton-container {
    display: none;
    padding: 1rem;
}

.skeleton-container.show {
    display: block;
}

.skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.5s infinite;
    border-radius: 4px;
}

@keyframes skeleton-loading {
    0% {
        background-position: 200% 0;
    }
    100% {
        background-position: -200% 0;
    }
}

.skeleton-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
}

.skeleton-header {
    background: #f8f9fa;
    height: 56px;
}

.skeleton-header .skeleton {
    height: 20px;
    margin: 18px 12px;
    background: linear-gradient(90deg, #e9ecef 25%, #dee2e6 50%, #e9ecef 75%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.5s infinite;
}

.skeleton-row {
    background: #ffffff;
    border-bottom: 1px solid rgba(17, 82, 114, 0.08);
    height: 72px;
}

.skeleton-row:nth-child(even) {
    background-color: rgba(240, 244, 247, 0.3);
}

.skeleton-cell {
    padding: 16px 12px;
    vertical-align: middle;
}

.skeleton-cell .skeleton {
    height: 16px;
    margin: 2px 0;
}

.skeleton-cell.date .skeleton {
    width: 80px;
    height: 14px;
}

.skeleton-cell.title .skeleton {
    width: 100%;
    margin-bottom: 6px;
}

.skeleton-cell.title .skeleton:nth-child(2) {
    width: 75%;
    height: 12px;
    opacity: 0.7;
}

.skeleton-cell.introducers .skeleton {
    width: 120px;
    height: 14px;
}

.skeleton-cell.type .skeleton {
    width: 70px;
    height: 20px;
    border-radius: 12px;
}

.skeleton-cell.status .skeleton {
    width: 60px;
    height: 18px;
    border-radius: 10px;
}

.skeleton-cell.actions {
    text-align: center;
}

.skeleton-cell.actions .skeleton {
    width: 60px;
    height: 28px;
    border-radius: 6px;
    display: inline-block;
    margin: 0 4px;
}

.skeleton-cell.actions .skeleton:nth-child(2) {
    width: 70px;
}

/* Search skeleton */
.skeleton-search {
    max-width: 400px;
    margin-bottom: 1.5rem;
}

.skeleton-search .skeleton {
    height: 38px;
    border-radius: 8px;
}

/* Tab skeleton */
.skeleton-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
    padding: 8px;
    background: rgba(17, 82, 114, 0.05);
    border-radius: 12px 12px 0 0;
}

.skeleton-tab {
    height: 42px;
    width: 120px;
    border-radius: 8px 8px 0 0;
}

.skeleton-tab:first-child {
    background: linear-gradient(90deg, #004a8b 25%, #115272 50%, #004a8b 75%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.5s infinite;
}

/* Enhanced skeleton for mobile */
@media (max-width: 768px) {
    .skeleton-cell {
        padding: 12px 8px;
    }
    
    .skeleton-cell .skeleton {
        height: 14px;
    }
    
    .skeleton-tab {
        width: 100px;
        height: 38px;
    }
}

/* Loading overlay */
.table-loading-overlay {
    position: relative;
}

.table-loading-overlay::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.7);
    z-index: 10;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.table-loading-overlay.loading::before {
    opacity: 1;
    visibility: visible;
}

.content-skeleton {
    display: none;
}

.content-skeleton.show {
    display: block;
}

.tab-content.loading .tab-pane {
    opacity: 0.5;
    pointer-events: none;
}

/* Modal skeleton overlay */
.modal-skeleton-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 2000;
}

.modal-skeleton {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.skeleton-modal-header {
    background: #f8f9fa;
    padding: 20px;
    border-bottom: 1px solid #dee2e6;
}

.skeleton-modal-body {
    padding: 20px;
}

.skeleton-modal-body .skeleton {
    border-radius: 4px;
}

/* File Preview Styles */
.file-preview-header, .file-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #dee2e6;
}

.file-controls {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.file-control-btn {
    background: linear-gradient(135deg, #004a8b 0%, #003866 100%);
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 74, 139, 0.2);
    display: flex;
    align-items: center;
    gap: 0.4rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.file-control-btn:hover:not(:disabled) {
    background: linear-gradient(135deg, #003866 0%, #004a8b 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 74, 139, 0.3);
}

.file-control-btn:disabled {
    background: #6c757d;
    cursor: not-allowed;
    opacity: 0.6;
    transform: none;
    box-shadow: 0 2px 8px rgba(108, 117, 125, 0.2);
}

.file-preview-container {
    margin-top: 1rem;
}

.file-preview-item {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    background: #f8f9fa;
}

.no-file-message {
    text-align: center;
    padding: 3rem 1rem;
    color: #6c757d;
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 8px;
}

.no-file-message i {
    font-size: 3rem;
    margin-bottom: 1rem;
    color: #adb5bd;
}

.no-file-message p {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 500;
}

.file-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid #dee2e6;
}

.file-name {
    font-weight: 500;
    color: #495057;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.file-type {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.25rem 0.5rem;
}

.preview-placeholder {
    text-align: center;
    padding: 2rem;
    color: #6c757d;
    background: white;
    border-radius: 6px;
    border: 1px solid #e9ecef;
}

.preview-placeholder i {
    display: block;
    margin-bottom: 1rem;
}

.preview-placeholder p {
    margin: 0.5rem 0;
}

.pdf-preview iframe,
.text-preview iframe {
    width: 100%;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    background: white;
}

.image-preview {
    text-align: center;
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border: 1px solid #e9ecef;
}

.image-preview img {
    max-width: 100%;
    height: auto;
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

/* Responsive file preview */
@media (max-width: 768px) {
    .file-preview-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
    }
    
    .file-controls {
        width: 100%;
        justify-content: center;
    }
    
    .file-control-btn {
        flex: 1;
        justify-content: center;
        padding: 0.6rem 1rem;
        font-size: 0.8rem;
    }
    
    .pdf-preview iframe,
    .text-preview iframe {
        height: 300px;
    }
    
    .file-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
}

@media (max-width: 480px) {
    .file-controls {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .file-control-btn {
        width: 100%;
    }
    
    .pdf-preview iframe,
    .text-preview iframe {
        height: 250px;
    }
}

/* Custom Alert Modal */
.custom-alert-modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.6);
    justify-content: center;
    align-items: center;
}

.custom-alert-content {
    background: white;
    padding: 30px;
    border-radius: 20px;
    box-shadow: 0 15px 50px rgba(0,0,0,0.3);
    max-width: 450px;
    width: 90%;
    animation: alertSlideIn 0.3s ease-out;
}

@keyframes alertSlideIn {
    from { opacity: 0; transform: translateY(-30px); }
    to { opacity: 1; transform: translateY(0); }
}

.custom-alert-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}

.custom-alert-icon {
    font-size: 3em;
}

.custom-alert-title {
    font-size: 1.5em;
    font-weight: 700;
    color: #0b3d6d;
    margin: 0;
}

.custom-alert-body {
    margin-bottom: 25px;
    color: #495057;
    line-height: 1.6;
}

.custom-alert-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.custom-alert-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
    font-size: 0.95em;
    background: linear-gradient(135deg, #0b3d6d, #1a5490);
    color: white;
}

.custom-alert-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(11, 61, 109, 0.3);
}

.custom-alert-btn.error {
    background: linear-gradient(135deg, #dc3545, #c82333);
}

.custom-alert-btn.error:hover {
    box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
}

.custom-alert-btn.warning {
    background: linear-gradient(135deg, #ffc107, #e0a800);
    color: #000;
}

.custom-alert-btn.warning:hover {
    box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
}

.custom-alert-btn.success {
    background: linear-gradient(135deg, #28a745, #20c997);
}

.custom-alert-btn.success:hover {
    box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
}

/* Review Icon Button Styling */
.review-icon-btn {
    background: transparent;
    border: none;
    padding: 0.25rem 0.5rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.review-icon-btn:hover {
    transform: scale(1.1);
}

.review-icon {
    color: #6c757d;
    font-size: 1.1rem;
    transition: all 0.3s ease;
}

.review-icon.completed {
    color: #28a745;
    animation: pulse-check 2s infinite;
}

@keyframes pulse-check {
    0%, 100% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.1);
        opacity: 0.8;
    }
}

/* Review Modal Styling */
.review-progress-container {
    background: linear-gradient(135deg, rgba(0, 74, 139, 0.05) 0%, rgba(17, 82, 114, 0.02) 100%);
    border-radius: 12px;
    padding: 1.5rem;
    border: 1px solid rgba(0, 74, 139, 0.1);
}

.review-progress-steps {
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
}

.review-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    position: relative;
    z-index: 2;
}

.review-step-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: #6c757d;
    transition: all 0.3s ease;
    border: 3px solid #e9ecef;
}

.review-step.completed .review-step-icon {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    border-color: #28a745;
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
}

.review-step.active .review-step-icon {
    background: linear-gradient(135deg, #004a8b 0%, #115272 100%);
    color: white;
    border-color: #004a8b;
    box-shadow: 0 4px 12px rgba(0, 74, 139, 0.3);
    animation: pulse-active 2s infinite;
}

@keyframes pulse-active {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.review-step-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #6c757d;
    text-align: center;
}

.review-step.completed .review-step-label {
    color: #28a745;
}

.review-step.active .review-step-label {
    color: #004a8b;
}

.review-step-connector {
    height: 3px;
    background: #e9ecef;
    flex: 1;
    margin: 0 -10px;
    position: relative;
    z-index: 1;
    margin-top: -25px;
}

.review-step.completed ~ .review-step-connector {
    background: linear-gradient(90deg, #28a745 0%, #e9ecef 100%);
}

.review-section {
    margin-bottom: 1.5rem;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.review-section:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.review-section-header {
    background: linear-gradient(135deg, rgba(0, 74, 139, 0.08) 0%, rgba(17, 82, 114, 0.05) 100%);
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e9ecef;
}

.review-section-header h6 {
    color: #004a8b;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.review-section-body {
    padding: 1.25rem;
}

.review-status-badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-weight: 600;
}

.review-status-badge.pending {
    background: #ffc107;
    color: #000;
}

.review-status-badge.completed {
    background: #28a745;
    color: white;
}

.review-history-item {
    background: #f8f9fa;
    border-left: 4px solid #004a8b;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.review-history-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
    font-size: 0.85rem;
    color: #6c757d;
}

.review-history-notes {
    color: #333;
    line-height: 1.6;
    white-space: pre-wrap;
}
</style>

</head>
<body>
    <!-- Super Admin Header -->
    <?php include __DIR__ . '/../../includes/super_header.php'; ?>
    
    <!-- Super Admin Sidebar -->
    <?php include __DIR__ . '/../../includes/super_sidebar.php'; ?>

<div class="container">
    <div id="alertContainer" class="mt-4"></div>
    <div class="card p-4">
        <ul class="nav nav-tabs" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="incoming-tab" data-bs-toggle="tab" data-bs-target="#incoming" type="button" role="tab" aria-controls="incoming" aria-selected="true">Incoming Task</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-measures" type="button" role="tab" aria-controls="pending-measures" aria-selected="false">In Review for Docketing</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="ordinances-tab" data-bs-toggle="tab" data-bs-target="#ordinances" type="button" role="tab" aria-controls="ordinances" aria-selected="false">Proposed Ordinances</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="resolutions-tab" data-bs-toggle="tab" data-bs-target="#resolutions" type="button" role="tab" aria-controls="resolutions" aria-selected="false">Proposed Resolutions</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="enacted-ordinances-tab" data-bs-toggle="tab" data-bs-target="#enacted-ordinances" type="button" role="tab" aria-controls="enacted-ordinances" aria-selected="false">Enacted Ordinances</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="enacted-resolutions-tab" data-bs-toggle="tab" data-bs-target="#enacted-resolutions" type="button" role="tab" aria-controls="enacted-resolutions" aria-selected="false">Enacted Resolutions</button>
            </li>
        </ul>

        <div class="tab-content" id="myTabContent">
            
            <div class="tab-pane fade show active" id="incoming" role="tabpanel" aria-labelledby="incoming-tab">
                <h2 class="mt-4 mb-3">Incoming Task</h2>
                <form method="GET" action="" class="mb-3">
                    <input type="hidden" name="tab" value="incoming">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" name="search" class="form-control" placeholder="Search by title" value="<?php echo $search_query; ?>">
                        </div>
                        <div class="col-md-4">
                            <select name="category" class="form-select">
                                <option value="">All Categories</option>
                                <option value="Agriculture" <?php echo $category_filter === 'Agriculture' ? 'selected' : ''; ?>>Agriculture</option>
                                <option value="Education" <?php echo $category_filter === 'Education' ? 'selected' : ''; ?>>Education</option>
                                <option value="Health" <?php echo $category_filter === 'Health' ? 'selected' : ''; ?>>Health</option>
                                <option value="Infrastructure" <?php echo $category_filter === 'Infrastructure' ? 'selected' : ''; ?>>Infrastructure</option>
                                <option value="Environment" <?php echo $category_filter === 'Environment' ? 'selected' : ''; ?>>Environment</option>
                                <option value="Social Services" <?php echo $category_filter === 'Social Services' ? 'selected' : ''; ?>>Social Services</option>
                                <option value="Economic Development" <?php echo $category_filter === 'Economic Development' ? 'selected' : ''; ?>>Economic Development</option>
                                <option value="Public Safety" <?php echo $category_filter === 'Public Safety' ? 'selected' : ''; ?>>Public Safety</option>
                                <option value="Transportation" <?php echo $category_filter === 'Transportation' ? 'selected' : ''; ?>>Transportation</option>
                                <option value="Finance" <?php echo $category_filter === 'Finance' ? 'selected' : ''; ?>>Finance</option>
                                <option value="Others" <?php echo $category_filter === 'Others' ? 'selected' : ''; ?>>Others</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" type="submit">Filter</button>
                        </div>
                    </div>
                    <?php if (!empty($search_query) || !empty($category_filter)): ?>
                        <div class="mt-2">
                            <a href="?tab=incoming&page=1" class="btn btn-sm btn-outline-danger">Clear All Filters</a>
                        </div>
                    <?php endif; ?>
                </form>
                
                <!-- Skeleton Loading for Incoming Task -->
                <div class="skeleton-container" id="incoming-skeleton">
                    <div class="table-responsive">
                        <table class="skeleton-table">
                            <thead class="skeleton-header">
                                <tr>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for($i = 0; $i < 5; $i++): ?>
                                <tr class="skeleton-row">
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell title">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                    <td class="skeleton-cell introducers"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell type"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell actions">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="table-responsive table-loading-overlay" id="incoming-table">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Date Created</th>
                                <th>Title</th>
                                <th>Introducers</th>
                                <th>Measure Type</th>
                                <th>Measure Status</th>
                                <th>Date & Time Submitted</th>                                
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Build search conditions (without category filter in SQL)
                            $search_conditions = "WHERE measure_status = 'Draft'";
                            if (!empty($search_query)) {
                                $search_terms = explode(' ', $search_query);
                                $search_conditions_array = [];
                                foreach ($search_terms as $term) {
                                    $search_conditions_array[] = "(measure_title LIKE ?)";
                                }
                                $search_conditions .= " AND (" . implode(" OR ", $search_conditions_array) . ")";
                            }

                            // Fetch all records matching search (we'll filter by category in PHP)
                            $sql_incoming = "SELECT * FROM m9_similaritychecking " . $search_conditions . " ORDER BY date_created DESC";
                            $stmt_incoming = $lgu2_conn->prepare($sql_incoming);
                            if ($stmt_incoming === false) {
                                die("Error preparing statement for incoming tasks: " . $lgu2_conn->error);
                            }
                            if (!empty($search_query)) {
                                $bind_params_data = [];
                                $types_data = '';
                                foreach ($search_terms as $term) {
                                    $types_data .= 's';
                                    $bind_params_data[] = "%" . $term . "%";
                                }
                                $stmt_incoming->bind_param($types_data, ...$bind_params_data);
                            }

                            if (!$stmt_incoming->execute()) {
                                die("Error executing statement for incoming tasks: " . $stmt_incoming->error);
                            }
                            $result_incoming = $stmt_incoming->get_result();
                            
                            // Filter by intelligent category detection
                            $filtered_rows = [];
                            while ($row = $result_incoming->fetch_assoc()) {
                                $detected_category = detectMeasureCategory($row['measure_title'], $row['measure_content']);
                                
                                // If category filter is active, only include matching categories
                                if (empty($category_filter) || $detected_category === $category_filter) {
                                    $filtered_rows[] = $row;
                                }
                            }
                            
                            // Apply pagination to filtered results
                            $total_records = count($filtered_rows);
                            $total_pages = ceil($total_records / $records_per_page);
                            $paginated_rows = array_slice($filtered_rows, $offset, $records_per_page);
                            
                            if (count($paginated_rows) > 0) {
                                foreach ($paginated_rows as $row) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['date_created']) . "</td>";
                                    // Apply truncation to title (Incoming Task) - Decode HTML entities first
                                    echo "<td>";
                                    $decodedTitle = html_entity_decode($row['measure_title'], ENT_QUOTES, 'UTF-8');
                                    echo "<strong>" . htmlspecialchars(substr($decodedTitle, 0, 50)) . "</strong>";
                                    if (strlen($decodedTitle) > 50) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    // Apply truncation to introducers (Incoming Task) - Decode HTML entities first
                                    echo "<td>";
                                    $decodedIntroducers = html_entity_decode($row['introducers'], ENT_QUOTES, 'UTF-8');
                                    echo htmlspecialchars(substr($decodedIntroducers, 0, 30));
                                    if (strlen($decodedIntroducers) > 30) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    echo "<td>" . htmlspecialchars($row['measure_type']) . "</td>";
                                    echo "<td>
                                        <div class='d-flex align-items-center justify-content-center gap-2'>
                                            <span>" . htmlspecialchars($row['measure_status']) . "</span>
                                            <button type='button' class='btn btn-sm review-icon-btn' 
                                                data-measure-id='" . $row['m9_SC_ID'] . "' 
                                                onclick='openReviewModal(" . $row['m9_SC_ID'] . ")' 
                                                title='Review Status'>
                                                <i class='fas fa-clipboard-check review-icon' id='review-icon-" . $row['m9_SC_ID'] . "'></i>
                                            </button>
                                        </div>
                                    </td>";
                                    // Handle datetime_submitted properly - show empty if NULL or default MySQL datetime
                                    $datetimeSubmitted = $row['datetime_submitted'] ?? '';
                                    if ($datetimeSubmitted === '' || $datetimeSubmitted === '0000-00-00 00:00:00' || $datetimeSubmitted === null) {
                                        $datetimeSubmitted = '';
                                    }
                                    echo "<td>" . htmlspecialchars($datetimeSubmitted) . "</td>";                                    
                                    echo "<td>
                                        <button type='button' class='modal1 btn btn-primary btn-sm btn-action view-task-btn' 
                                            data-measure='" . htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') . "'
                                            onclick='viewMeasureEnhanced(JSON.parse(this.getAttribute(\"data-measure\")))'>VIEW</button>
                                        <button class='btn btn-success btn-sm btn-action send-task-btn' data-bs-toggle='modal' data-bs-target='#ITSendModal'
                                            data-measure-send='" . htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') . "'>SEND</button>
                                        </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7'>No incoming tasks found.</td></tr>";
                            }
                            $stmt_incoming->close();
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-container">
                    <span>Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php if($current_page <= 1) echo 'disabled'; ?>">
                                <a class="page-link" href="?page=<?php echo $current_page - 1; ?>&search=<?php echo urlencode($search_query); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php if($current_page == $i) echo 'active'; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php if($current_page >= $total_pages) echo 'disabled'; ?>">
                                <a class="page-link" href="?page=<?php echo $current_page + 1; ?>&search=<?php echo urlencode($search_query); ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

            <div class="tab-pane fade" id="pending-measures" role="tabpanel" aria-labelledby="pending-tab">
                <h2 class="mt-4 mb-3">In Review for Docketing</h2>
                
                <!-- Date Filter and Search Form -->
                <form method="GET" action="" class="mb-3">
                    <input type="hidden" name="tab" value="pending-measures">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label for="date_from" class="form-label small fw-bold" style="color: #004a8b;">Start Date</label>
                            <input type="date" name="date_from" id="date_from" class="form-control" value="<?php echo isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : ''; ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label small fw-bold" style="color: #004a8b;">End Date</label>
                            <input type="date" name="date_to" id="date_to" class="form-control" value="<?php echo isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="search_pending" class="form-label small fw-bold" style="color: #004a8b;">Search</label>
                            <input type="text" name="search_pending" id="search_pending" class="form-control" placeholder="Search by title" value="<?php echo isset($_GET['search_pending']) ? htmlspecialchars($_GET['search_pending']) : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="category_pending" class="form-label small fw-bold" style="color: #004a8b;">Category</label>
                            <select name="category_pending" id="category_pending" class="form-select">
                                <option value="">All Categories</option>
                                <option value="Agriculture" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Agriculture') ? 'selected' : ''; ?>>Agriculture</option>
                                <option value="Education" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Education') ? 'selected' : ''; ?>>Education</option>
                                <option value="Health" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Health') ? 'selected' : ''; ?>>Health</option>
                                <option value="Infrastructure" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Infrastructure') ? 'selected' : ''; ?>>Infrastructure</option>
                                <option value="Environment" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Environment') ? 'selected' : ''; ?>>Environment</option>
                                <option value="Social Services" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Social Services') ? 'selected' : ''; ?>>Social Services</option>
                                <option value="Economic Development" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Economic Development') ? 'selected' : ''; ?>>Economic Development</option>
                                <option value="Public Safety" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Public Safety') ? 'selected' : ''; ?>>Public Safety</option>
                                <option value="Transportation" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Transportation') ? 'selected' : ''; ?>>Transportation</option>
                                <option value="Finance" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                                <option value="Others" <?php echo (isset($_GET['category_pending']) && $_GET['category_pending'] === 'Others') ? 'selected' : ''; ?>>Others</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <div class="d-flex gap-1">
                                <button class="btn btn-outline-secondary flex-grow-1" type="submit"><i class="fas fa-search"></i> Filter</button>
                                <?php if ((isset($_GET['search_pending']) && !empty($_GET['search_pending'])) || (isset($_GET['date_from']) && !empty($_GET['date_from'])) || (isset($_GET['date_to']) && !empty($_GET['date_to'])) || (isset($_GET['category_pending']) && !empty($_GET['category_pending']))): ?>
                                    <a href="?tab=pending-measures" class="btn btn-outline-danger"><i class="fas fa-times"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
                
                <!-- Generate All PDF Button -->
                <div class="mb-3 d-flex justify-content-end">
                    <button type="button" class="btn btn-danger" id="generateAllPdfBtn" onclick="openBulkPdfModal()" style="background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%); border: none; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);">
                        <i class="fas fa-file-pdf me-1"></i> Generate All PDF
                    </button>
                </div>
                
                <!-- Skeleton Loading for Pending Measures -->
                <div class="skeleton-container" id="pending-measures-skeleton">
                    <div class="table-responsive">
                        <table class="skeleton-table">
                            <thead class="skeleton-header">
                                <tr>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for($i = 0; $i < 5; $i++): ?>
                                <tr class="skeleton-row">
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell title">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                    <td class="skeleton-cell introducers"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell type"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell actions">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="table-responsive" id="pending-measures-table">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Date Created</th>
                                <th>Title</th>
                                <th>Introducers</th>
                                <th>Measure Type</th>
                                <th>Measure Status</th>
                                <th>Date & Time Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $current_page_pending = isset($_GET['page_pending']) ? (int)$_GET['page_pending'] : 1;
                            $search_query_pending = isset($_GET['search_pending']) ? htmlspecialchars($_GET['search_pending']) : '';
                            $category_filter_pending = isset($_GET['category_pending']) ? htmlspecialchars($_GET['category_pending']) : '';
                            $date_from = isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : '';
                            $date_to = isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : '';
                            $offset_pending = ($current_page_pending - 1) * $records_per_page;

                            $search_conditions_pending = "WHERE measure_status = 'In Review for Docketing'";
                            $bind_params_pending = [];
                            $types_pending = '';
                            
                            // Add date range filter
                            if (!empty($date_from)) {
                                $search_conditions_pending .= " AND DATE(date_created) >= ?";
                                $types_pending .= 's';
                                $bind_params_pending[] = $date_from;
                            }
                            if (!empty($date_to)) {
                                $search_conditions_pending .= " AND DATE(date_created) <= ?";
                                $types_pending .= 's';
                                $bind_params_pending[] = $date_to;
                            }
                            
                            // Add search filter
                            if (!empty($search_query_pending)) {
                                $search_terms_pending = explode(' ', $search_query_pending);
                                $search_conditions_array_pending = [];
                                foreach ($search_terms_pending as $term) {
                                    $search_conditions_array_pending[] = "(measure_title LIKE ?)";
                                }
                                $search_conditions_pending .= " AND (" . implode(" OR ", $search_conditions_array_pending) . ")";
                                foreach ($search_terms_pending as $term) {
                                    $types_pending .= 's';
                                    $bind_params_pending[] = "%" . $term . "%";
                                }
                            }
                            
                            // Fetch all records (category filter will be applied in PHP)
                            $order_by_pending = " ORDER BY date_created DESC";
                            $sql_pending = "SELECT * FROM m9_similaritychecking " . $search_conditions_pending . $order_by_pending;
                            $stmt_pending = $lgu2_conn->prepare($sql_pending);
                            if ($stmt_pending === false) {
                                die("Error preparing statement for pending measures: " . $lgu2_conn->error);
                            }
                            
                            if (!empty($bind_params_pending)) {
                                $stmt_pending->bind_param($types_pending, ...$bind_params_pending);
                            }

                            if (!$stmt_pending->execute()) {
                                die("Error executing statement for pending measures: " . $stmt_pending->error);
                            }
                            $result_pending = $stmt_pending->get_result();
                            
                            // Filter by intelligent category detection
                            $all_pending_measures = [];
                            $filtered_pending_rows = [];
                            while ($row_temp = $result_pending->fetch_assoc()) {
                                $detected_category = detectMeasureCategory($row_temp['measure_title'], $row_temp['measure_content']);
                                
                                if (empty($category_filter_pending) || $detected_category === $category_filter_pending) {
                                    $filtered_pending_rows[] = $row_temp;
                                    $all_pending_measures[] = $row_temp;
                                }
                            }
                            
                            // Apply pagination to filtered results
                            $total_records_pending = count($filtered_pending_rows);
                            $total_pages_pending = ceil($total_records_pending / $records_per_page);
                            $pending_rows_temp = array_slice($filtered_pending_rows, $offset_pending, $records_per_page);
                            
                            if (count($pending_rows_temp) > 0) {
                                foreach ($pending_rows_temp as $row) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['date_created']) . "</td>";
                                    // Apply truncation to title with HTML entity decoding
                                    echo "<td>";
                                    $decodedTitle = html_entity_decode($row['measure_title'], ENT_QUOTES, 'UTF-8');
                                    echo "<strong>" . htmlspecialchars(substr($decodedTitle, 0, 50)) . "</strong>";
                                    if (strlen($decodedTitle) > 50) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    // Apply truncation to introducers with HTML entity decoding
                                    echo "<td>";
                                    $decodedIntroducers = html_entity_decode($row['introducers'], ENT_QUOTES, 'UTF-8');
                                    echo htmlspecialchars(substr($decodedIntroducers, 0, 30));
                                    if (strlen($decodedIntroducers) > 30) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    echo "<td>" . htmlspecialchars($row['measure_type']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['measure_status']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['datetime_submitted'] ?? '') . "</td>";                                    
                                    echo "<td>
                                        <button type='button' class='modal1 btn btn-primary btn-sm btn-action view-task-btn' 
                                            data-measure='" . htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') . "'
                                            onclick='viewMeasureEnhanced(JSON.parse(this.getAttribute(\"data-measure\")))''>VIEW</button>
                                        <button type='button' class='btn btn-danger btn-sm btn-action ms-1 generate-pdf-btn' 
                                            data-measure='" . htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') . "'
                                            onclick='openPdfModal(JSON.parse(this.getAttribute(\"data-measure\")))' 
                                            title='Generate PDF'>
                                            <i class='fas fa-file'></i> PDF
                                        </button>
                                        <button type='button' class='btn btn-warning btn-sm btn-action ms-1' 
                                            onclick='openRetrieveModal(" . $row['m9_SC_ID'] . ", \"" . htmlspecialchars(addslashes($row['measure_title']), ENT_QUOTES) . "\")' 
                                            title='Retrieve to Incoming Task'>
                                            <i class='fas fa-undo'></i>
                                        </button>
                                        </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7'>No pending measures found.</td></tr>";
                            }
                            $stmt_pending->close();
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-container">
                    <span>Page <?php echo $current_page_pending; ?> of <?php echo max(1, $total_pages_pending); ?></span>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php if($current_page_pending <= 1) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_pending=<?php echo $current_page_pending - 1; ?>&search_pending=<?php echo urlencode($search_query_pending); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&tab=pending-measures" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages_pending; $i++): ?>
                                <li class="page-item <?php if($current_page_pending == $i) echo 'active'; ?>">
                                    <a class="page-link" href="?page_pending=<?php echo $i; ?>&search_pending=<?php echo urlencode($search_query_pending); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&tab=pending-measures"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php if($current_page_pending >= $total_pages_pending) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_pending=<?php echo $current_page_pending + 1; ?>&search_pending=<?php echo urlencode($search_query_pending); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&tab=pending-measures" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                
                <!-- Hidden data for bulk PDF generation -->
                <script>
                    var allPendingMeasures = <?php echo json_encode($all_pending_measures); ?>;
                    var currentDateFilter = {
                        from: '<?php echo $date_from; ?>',
                        to: '<?php echo $date_to; ?>'
                    };
                </script>
            </div>

            <div class="tab-pane fade" id="ordinances" role="tabpanel" aria-labelledby="ordinances-tab">
                <h2 class="mt-4 mb-3">Proposed Ordinances</h2>
                <form method="GET" action="" class="mb-3">
                    <input type="hidden" name="tab" value="ordinances">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" name="search_ordinances" class="form-control" placeholder="Search by title or docket number" value="<?php echo isset($_GET['search_ordinances']) ? htmlspecialchars($_GET['search_ordinances']) : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <select name="category_ordinances" class="form-select">
                                <option value="">All Categories</option>
                                <option value="Agriculture" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Agriculture') ? 'selected' : ''; ?>>Agriculture</option>
                                <option value="Education" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Education') ? 'selected' : ''; ?>>Education</option>
                                <option value="Health" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Health') ? 'selected' : ''; ?>>Health</option>
                                <option value="Infrastructure" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Infrastructure') ? 'selected' : ''; ?>>Infrastructure</option>
                                <option value="Environment" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Environment') ? 'selected' : ''; ?>>Environment</option>
                                <option value="Social Services" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Social Services') ? 'selected' : ''; ?>>Social Services</option>
                                <option value="Economic Development" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Economic Development') ? 'selected' : ''; ?>>Economic Development</option>
                                <option value="Public Safety" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Public Safety') ? 'selected' : ''; ?>>Public Safety</option>
                                <option value="Transportation" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Transportation') ? 'selected' : ''; ?>>Transportation</option>
                                <option value="Finance" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                                <option value="Others" <?php echo (isset($_GET['category_ordinances']) && $_GET['category_ordinances'] === 'Others') ? 'selected' : ''; ?>>Others</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" type="submit">Filter</button>
                        </div>
                    </div>
                    <?php if ((isset($_GET['search_ordinances']) && !empty($_GET['search_ordinances'])) || (isset($_GET['category_ordinances']) && !empty($_GET['category_ordinances']))): ?>
                        <div class="mt-2">
                            <a href="?tab=ordinances&page=1" class="btn btn-sm btn-outline-danger">Clear All Filters</a>
                        </div>
                    <?php endif; ?>
                </form>
                
                <!-- Skeleton Loading for Proposed Ordinances -->
                <div class="skeleton-container" id="ordinances-skeleton">
                    <div class="table-responsive">
                        <table class="skeleton-table">
                            <thead class="skeleton-header">
                                <tr>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for($i = 0; $i < 5; $i++): ?>
                                <tr class="skeleton-row">
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell title">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                    <td class="skeleton-cell introducers"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell type"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell actions">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="table-responsive" id="ordinances-table">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Docket No.</th>
                                <th>Title</th>
                                <th>Introducers</th>
                                <th>Checking Remarks in Pending Measures</th>
                                <th>Checking Remarks in Approved Measures</th>
                                <th>Measure Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $current_page_ordinances = isset($_GET['page_ordinances']) ? (int)$_GET['page_ordinances'] : 1;
                            $search_query_ordinances = isset($_GET['search_ordinances']) ? htmlspecialchars($_GET['search_ordinances']) : '';
                            $category_filter_ordinances = isset($_GET['category_ordinances']) ? htmlspecialchars($_GET['category_ordinances']) : '';
                            $offset_ordinances = ($current_page_ordinances - 1) * $records_per_page;

                            $search_conditions_ordinances = "WHERE measure_type = 'Ordinance' AND measure_status IN ('1st Reading', '2nd Reading', '3rd Reading')";
                            if (!empty($search_query_ordinances)) {
                                $search_terms_ordinances = explode(' ', $search_query_ordinances);
                                $search_conditions_array_ordinances = [];
                                foreach ($search_terms_ordinances as $term) {
                                    $search_conditions_array_ordinances[] = "(measure_title LIKE ? OR docket_no LIKE ?)";
                                }
                                $search_conditions_ordinances .= " AND (" . implode(" OR ", $search_conditions_array_ordinances) . ")";
                            }
                            // Fetch all records (category filter will be applied in PHP)
                            $sql_ordinances = "SELECT * FROM m9_similaritychecking " . $search_conditions_ordinances;
                            $stmt_ordinances = $lgu2_conn->prepare($sql_ordinances);
                            if ($stmt_ordinances === false) {
                                die("Error preparing statement for ordinances: " . $lgu2_conn->error);
                            }
                            if (!empty($search_query_ordinances)) {
                                $bind_params_data_ordinances = [];
                                $types_data_ordinances = '';
                                foreach ($search_terms_ordinances as $term) {
                                    $types_data_ordinances .= 'ss';
                                    $bind_params_data_ordinances[] = "%" . $term . "%";
                                    $bind_params_data_ordinances[] = "%" . $term . "%";
                                }
                                $stmt_ordinances->bind_param($types_data_ordinances, ...$bind_params_data_ordinances);
                            }

                            if (!$stmt_ordinances->execute()) {
                                die("Error executing statement for ordinances: " . $stmt_ordinances->error);
                            }
                            $result_ordinances = $stmt_ordinances->get_result();
                            
                            // Filter by intelligent category detection
                            $filtered_ordinances = [];
                            while ($row_temp = $result_ordinances->fetch_assoc()) {
                                $detected_category = detectMeasureCategory($row_temp['measure_title'], $row_temp['measure_content']);
                                
                                if (empty($category_filter_ordinances) || $detected_category === $category_filter_ordinances) {
                                    $filtered_ordinances[] = $row_temp;
                                }
                            }
                            
                            // Apply pagination to filtered results
                            $total_records_ordinances = count($filtered_ordinances);
                            $total_pages_ordinances = ceil($total_records_ordinances / $records_per_page);
                            $paginated_ordinances = array_slice($filtered_ordinances, $offset_ordinances, $records_per_page);
                            
                            if (count($paginated_ordinances) > 0) {
                                foreach ($paginated_ordinances as $row) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['docket_no'] ?? '') . "</td>";
                                    // Apply truncation to title with HTML entity decoding
                                    echo "<td>";
                                    $decodedTitle = html_entity_decode($row['measure_title'], ENT_QUOTES, 'UTF-8');
                                    echo "<strong>" . htmlspecialchars(substr($decodedTitle, 0, 50)) . "</strong>";
                                    if (strlen($decodedTitle) > 50) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    // Apply truncation to introducers with HTML entity decoding
                                    echo "<td>";
                                    $decodedIntroducers = html_entity_decode($row['introducers'], ENT_QUOTES, 'UTF-8');
                                    echo htmlspecialchars(substr($decodedIntroducers, 0, 30));
                                    if (strlen($decodedIntroducers) > 30) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    echo "<td>" . htmlspecialchars($row['checking_remarks_pending'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['checking_remarks_approved'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['measure_status']) . "</td>";                                 
                                    echo "<td>
                                        <button type='button' class='modal1 btn btn-primary btn-sm btn-action view-task-btn' 
                                            data-measure='" . htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') . "'
                                            onclick='viewMeasureEnhanced(JSON.parse(this.getAttribute(\"data-measure\")))'>VIEW</button>
                                        </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7'>No ordinances found.</td></tr>";
                            }
                            $stmt_ordinances->close();
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-container">
                    <span>Page <?php echo $current_page_ordinances; ?> of <?php echo $total_pages_ordinances; ?></span>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php if($current_page_ordinances <= 1) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_ordinances=<?php echo $current_page_ordinances - 1; ?>&search_ordinances=<?php echo urlencode($search_query_ordinances); ?>&tab=ordinances" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages_ordinances; $i++): ?>
                                <li class="page-item <?php if($current_page_ordinances == $i) echo 'active'; ?>">
                                    <a class="page-link" href="?page_ordinances=<?php echo $i; ?>&search_ordinances=<?php echo urlencode($search_query_ordinances); ?>&tab=ordinances"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php if($current_page_ordinances >= $total_pages_ordinances) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_ordinances=<?php echo $current_page_ordinances + 1; ?>&search_ordinances=<?php echo urlencode($search_query_ordinances); ?>&tab=ordinances" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

            <div class="tab-pane fade" id="resolutions" role="tabpanel" aria-labelledby="resolutions-tab">
                <h2 class="mt-4 mb-3">Proposed Resolutions</h2>
                <form method="GET" action="" class="mb-3">
                    <input type="hidden" name="tab" value="resolutions">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" name="search_resolutions" class="form-control" placeholder="Search by title or docket number" value="<?php echo isset($_GET['search_resolutions']) ? htmlspecialchars($_GET['search_resolutions']) : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <select name="category_resolutions" class="form-select">
                                <option value="">All Categories</option>
                                <option value="Agriculture" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Agriculture') ? 'selected' : ''; ?>>Agriculture</option>
                                <option value="Education" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Education') ? 'selected' : ''; ?>>Education</option>
                                <option value="Health" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Health') ? 'selected' : ''; ?>>Health</option>
                                <option value="Infrastructure" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Infrastructure') ? 'selected' : ''; ?>>Infrastructure</option>
                                <option value="Environment" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Environment') ? 'selected' : ''; ?>>Environment</option>
                                <option value="Social Services" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Social Services') ? 'selected' : ''; ?>>Social Services</option>
                                <option value="Economic Development" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Economic Development') ? 'selected' : ''; ?>>Economic Development</option>
                                <option value="Public Safety" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Public Safety') ? 'selected' : ''; ?>>Public Safety</option>
                                <option value="Transportation" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Transportation') ? 'selected' : ''; ?>>Transportation</option>
                                <option value="Finance" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                                <option value="Others" <?php echo (isset($_GET['category_resolutions']) && $_GET['category_resolutions'] === 'Others') ? 'selected' : ''; ?>>Others</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" type="submit">Filter</button>
                        </div>
                    </div>
                    <?php if ((isset($_GET['search_resolutions']) && !empty($_GET['search_resolutions'])) || (isset($_GET['category_resolutions']) && !empty($_GET['category_resolutions']))): ?>
                        <div class="mt-2">
                            <a href="?tab=resolutions&page=1" class="btn btn-sm btn-outline-danger">Clear All Filters</a>
                        </div>
                    <?php endif; ?>
                </form>
                
                <!-- Skeleton Loading for Proposed Resolutions -->
                <div class="skeleton-container" id="resolutions-skeleton">
                    <div class="table-responsive">
                        <table class="skeleton-table">
                            <thead class="skeleton-header">
                                <tr>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for($i = 0; $i < 5; $i++): ?>
                                <tr class="skeleton-row">
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell title">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                    <td class="skeleton-cell introducers"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell actions">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="table-responsive" id="resolutions-table">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Docket No.</th>
                                <th>Title</th>
                                <th>Introducers</th>
                                <th>Checking Remarks in Pending Measures</th>
                                <th>Checking Remarks in Approved Measures</th>
                                <th>Measure Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $current_page_resolutions = isset($_GET['page_resolutions']) ? (int)$_GET['page_resolutions'] : 1;
                            $search_query_resolutions = isset($_GET['search_resolutions']) ? htmlspecialchars($_GET['search_resolutions']) : '';
                            $category_filter_resolutions = isset($_GET['category_resolutions']) ? htmlspecialchars($_GET['category_resolutions']) : '';
                            $offset_resolutions = ($current_page_resolutions - 1) * $records_per_page;

                            $search_conditions_resolutions = "WHERE measure_type = 'Resolution' AND measure_status IN ('1st Reading', '2nd Reading', '3rd Reading')";
                            if (!empty($search_query_resolutions)) {
                                $search_terms_resolutions = explode(' ', $search_query_resolutions);
                                $search_conditions_array_resolutions = [];
                                foreach ($search_terms_resolutions as $term) {
                                    $search_conditions_array_resolutions[] = "(measure_title LIKE ? OR docket_no LIKE ?)";
                                }
                                $search_conditions_resolutions .= " AND (" . implode(" OR ", $search_conditions_array_resolutions) . ")";
                            }
                            // Fetch all records (category filter will be applied in PHP)
                            $sql_resolutions = "SELECT * FROM m9_similaritychecking " . $search_conditions_resolutions;
                            $stmt_resolutions = $lgu2_conn->prepare($sql_resolutions);
                            if ($stmt_resolutions === false) {
                                die("Error preparing statement for resolutions: " . $lgu2_conn->error);
                            }
                            if (!empty($search_query_resolutions)) {
                                $bind_params_data_resolutions = [];
                                $types_data_resolutions = '';
                                foreach ($search_terms_resolutions as $term) {
                                    $types_data_resolutions .= 'ss';
                                    $bind_params_data_resolutions[] = "%" . $term . "%";
                                    $bind_params_data_resolutions[] = "%" . $term . "%";
                                }
                                $stmt_resolutions->bind_param($types_data_resolutions, ...$bind_params_data_resolutions);
                            }

                            if (!$stmt_resolutions->execute()) {
                                die("Error executing statement for resolutions: " . $stmt_resolutions->error);
                            }
                            $result_resolutions = $stmt_resolutions->get_result();
                            
                            // Filter by intelligent category detection
                            $filtered_resolutions = [];
                            while ($row_temp = $result_resolutions->fetch_assoc()) {
                                $detected_category = detectMeasureCategory($row_temp['measure_title'], $row_temp['measure_content']);
                                
                                if (empty($category_filter_resolutions) || $detected_category === $category_filter_resolutions) {
                                    $filtered_resolutions[] = $row_temp;
                                }
                            }
                            
                            // Apply pagination to filtered results
                            $total_records_resolutions = count($filtered_resolutions);
                            $total_pages_resolutions = ceil($total_records_resolutions / $records_per_page);
                            $paginated_resolutions = array_slice($filtered_resolutions, $offset_resolutions, $records_per_page);
                            
                            if (count($paginated_resolutions) > 0) {
                                foreach ($paginated_resolutions as $row) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['docket_no'] ?? '') . "</td>";
                                    // Apply truncation to title with HTML entity decoding
                                    echo "<td>";
                                    $decodedTitle = html_entity_decode($row['measure_title'], ENT_QUOTES, 'UTF-8');
                                    echo "<strong>" . htmlspecialchars(substr($decodedTitle, 0, 50)) . "</strong>";
                                    if (strlen($decodedTitle) > 50) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    // Apply truncation to introducers with HTML entity decoding
                                    echo "<td>";
                                    $decodedIntroducers = html_entity_decode($row['introducers'], ENT_QUOTES, 'UTF-8');
                                    echo htmlspecialchars(substr($decodedIntroducers, 0, 30));
                                    if (strlen($decodedIntroducers) > 30) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    echo "<td>" . htmlspecialchars($row['checking_remarks_pending'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['checking_remarks_approved'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['measure_status']) . "</td>";                                   
                                    echo "<td>
                                        <button type='button' class='modal1 btn btn-primary btn-sm btn-action view-task-btn' 
                                            data-measure='" . htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') . "'
                                            onclick='viewMeasureEnhanced(JSON.parse(this.getAttribute(\"data-measure\")))'>VIEW</button>
                                        </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7'>No resolutions found.</td></tr>";
                            }
                            $stmt_resolutions->close();
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-container">
                    <span>Page <?php echo $current_page_resolutions; ?> of <?php echo $total_pages_resolutions; ?></span>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php if($current_page_resolutions <= 1) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_resolutions=<?php echo $current_page_resolutions - 1; ?>&search_resolutions=<?php echo urlencode($search_query_resolutions); ?>&tab=resolutions" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages_resolutions; $i++): ?>
                                <li class="page-item <?php if($current_page_resolutions == $i) echo 'active'; ?>">
                                    <a class="page-link" href="?page_resolutions=<?php echo $i; ?>&search_resolutions=<?php echo urlencode($search_query_resolutions); ?>&tab=resolutions"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php if($current_page_resolutions >= $total_pages_resolutions) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_resolutions=<?php echo $current_page_resolutions + 1; ?>&search_resolutions=<?php echo urlencode($search_query_resolutions); ?>&tab=resolutions" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

            <div class="tab-pane fade" id="enacted-ordinances" role="tabpanel" aria-labelledby="enacted-ordinances-tab">
                <h2 class="mt-4 mb-3">Enacted Ordinances</h2>
                <form method="GET" action="" class="mb-3">
                    <input type="hidden" name="tab" value="enacted-ordinances">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" name="search_enacted_ordinances" class="form-control" placeholder="Search by title or SP number" value="<?php echo isset($_GET['search_enacted_ordinances']) ? htmlspecialchars($_GET['search_enacted_ordinances']) : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <select name="category_enacted_ordinances" class="form-select">
                                <option value="">All Categories</option>
                                <option value="Agriculture" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Agriculture') ? 'selected' : ''; ?>>Agriculture</option>
                                <option value="Education" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Education') ? 'selected' : ''; ?>>Education</option>
                                <option value="Health" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Health') ? 'selected' : ''; ?>>Health</option>
                                <option value="Infrastructure" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Infrastructure') ? 'selected' : ''; ?>>Infrastructure</option>
                                <option value="Environment" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Environment') ? 'selected' : ''; ?>>Environment</option>
                                <option value="Social Services" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Social Services') ? 'selected' : ''; ?>>Social Services</option>
                                <option value="Economic Development" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Economic Development') ? 'selected' : ''; ?>>Economic Development</option>
                                <option value="Public Safety" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Public Safety') ? 'selected' : ''; ?>>Public Safety</option>
                                <option value="Transportation" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Transportation') ? 'selected' : ''; ?>>Transportation</option>
                                <option value="Finance" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                                <option value="Others" <?php echo (isset($_GET['category_enacted_ordinances']) && $_GET['category_enacted_ordinances'] === 'Others') ? 'selected' : ''; ?>>Others</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" type="submit">Filter</button>
                        </div>
                    </div>
                    <?php if ((isset($_GET['search_enacted_ordinances']) && !empty($_GET['search_enacted_ordinances'])) || (isset($_GET['category_enacted_ordinances']) && !empty($_GET['category_enacted_ordinances']))): ?>
                        <div class="mt-2">
                            <a href="?tab=enacted-ordinances&page=1" class="btn btn-sm btn-outline-danger">Clear All Filters</a>
                        </div>
                    <?php endif; ?>
                </form>
                
                <!-- Skeleton Loading for Enacted Ordinances -->
                <div class="skeleton-container" id="enacted-ordinances-skeleton">
                    <div class="table-responsive">
                        <table class="skeleton-table">
                            <thead class="skeleton-header">
                                <tr>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for($i = 0; $i < 5; $i++): ?>
                                <tr class="skeleton-row">
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell title">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                    <td class="skeleton-cell introducers"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell actions">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="table-responsive" id="enacted-ordinances-table">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>SP No.</th>
                                <th>Title</th>
                                <th>Introducers</th>
                                <th>Checking Remarks in Pending Measures</th>
                                <th>Checking Remarks in Approved Measures</th>
                                <th>Measure Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $current_page_enacted_ordinances = isset($_GET['page_enacted_ordinances']) ? (int)$_GET['page_enacted_ordinances'] : 1;
                            $search_query_enacted_ordinances = isset($_GET['search_enacted_ordinances']) ? htmlspecialchars($_GET['search_enacted_ordinances']) : '';
                            $category_filter_enacted_ordinances = isset($_GET['category_enacted_ordinances']) ? htmlspecialchars($_GET['category_enacted_ordinances']) : '';
                            $offset_enacted_ordinances = ($current_page_enacted_ordinances - 1) * $records_per_page;

                            $search_conditions_enacted_ordinances = "WHERE measure_type = 'Ordinance' AND measure_status = 'Enacted'";
                            if (!empty($search_query_enacted_ordinances)) {
                                $search_terms_enacted_ordinances = explode(' ', $search_query_enacted_ordinances);
                                $search_conditions_array_enacted_ordinances = [];
                                foreach ($search_terms_enacted_ordinances as $term) {
                                    $search_conditions_array_enacted_ordinances[] = "(measure_title LIKE ? OR sp_no LIKE ?)";
                                }
                                $search_conditions_enacted_ordinances .= " AND (" . implode(" OR ", $search_conditions_array_enacted_ordinances) . ")";
                            }
                            // Fetch all records (category filter will be applied in PHP)
                            $sql_enacted_ordinances = "SELECT * FROM m9_similaritychecking " . $search_conditions_enacted_ordinances;
                            $stmt_enacted_ordinances = $lgu2_conn->prepare($sql_enacted_ordinances);
                            if ($stmt_enacted_ordinances === false) {
                                die("Error preparing statement for enacted ordinances: " . $lgu2_conn->error);
                            }
                            if (!empty($search_query_enacted_ordinances)) {
                                $bind_params_data_enacted_ordinances = [];
                                $types_data_enacted_ordinances = '';
                                foreach ($search_terms_enacted_ordinances as $term) {
                                    $types_data_enacted_ordinances .= 'ss';
                                    $bind_params_data_enacted_ordinances[] = "%" . $term . "%";
                                    $bind_params_data_enacted_ordinances[] = "%" . $term . "%";
                                }
                                $stmt_enacted_ordinances->bind_param($types_data_enacted_ordinances, ...$bind_params_data_enacted_ordinances);
                            }

                            if (!$stmt_enacted_ordinances->execute()) {
                                die("Error executing statement for enacted ordinances: " . $stmt_enacted_ordinances->error);
                            }
                            $result_enacted_ordinances = $stmt_enacted_ordinances->get_result();
                            
                            // Filter by intelligent category detection
                            $filtered_enacted_ordinances = [];
                            while ($row_temp = $result_enacted_ordinances->fetch_assoc()) {
                                $detected_category = detectMeasureCategory($row_temp['measure_title'], $row_temp['measure_content']);
                                
                                if (empty($category_filter_enacted_ordinances) || $detected_category === $category_filter_enacted_ordinances) {
                                    $filtered_enacted_ordinances[] = $row_temp;
                                }
                            }
                            
                            // Apply pagination to filtered results
                            $total_records_enacted_ordinances = count($filtered_enacted_ordinances);
                            $total_pages_enacted_ordinances = ceil($total_records_enacted_ordinances / $records_per_page);
                            $paginated_enacted_ordinances = array_slice($filtered_enacted_ordinances, $offset_enacted_ordinances, $records_per_page);
                            
                            if (count($paginated_enacted_ordinances) > 0) {
                                foreach ($paginated_enacted_ordinances as $row) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['sp_no'] ?? '') . "</td>";
                                    // Apply truncation to title with HTML entity decoding
                                    echo "<td>";
                                    $decodedTitle = html_entity_decode($row['measure_title'], ENT_QUOTES, 'UTF-8');
                                    echo "<strong>" . htmlspecialchars(substr($decodedTitle, 0, 50)) . "</strong>";
                                    if (strlen($decodedTitle) > 50) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    // Apply truncation to introducers with HTML entity decoding
                                    echo "<td>";
                                    $decodedIntroducers = html_entity_decode($row['introducers'], ENT_QUOTES, 'UTF-8');
                                    echo htmlspecialchars(substr($decodedIntroducers, 0, 30));
                                    if (strlen($decodedIntroducers) > 30) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    echo "<td>" . htmlspecialchars($row['checking_remarks_pending'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['checking_remarks_approved'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['measure_status']) . "</td>";                                 
                                    echo "<td>
                                        <button type='button' class='modal1 btn btn-primary btn-sm btn-action view-task-btn' 
                                            data-measure='" . htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') . "'
                                            onclick='viewMeasureEnhanced(JSON.parse(this.getAttribute(\"data-measure\")))'>VIEW</button>
                                        </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7'>No enacted ordinances found.</td></tr>";
                            }
                            $stmt_enacted_ordinances->close();
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-container">
                    <span>Page <?php echo $current_page_enacted_ordinances; ?> of <?php echo $total_pages_enacted_ordinances; ?></span>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php if($current_page_enacted_ordinances <= 1) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_enacted_ordinances=<?php echo $current_page_enacted_ordinances - 1; ?>&search_enacted_ordinances=<?php echo urlencode($search_query_enacted_ordinances); ?>&tab=enacted-ordinances" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages_enacted_ordinances; $i++): ?>
                                <li class="page-item <?php if($current_page_enacted_ordinances == $i) echo 'active'; ?>">
                                    <a class="page-link" href="?page_enacted_ordinances=<?php echo $i; ?>&search_enacted_ordinances=<?php echo urlencode($search_query_enacted_ordinances); ?>&tab=enacted-ordinances"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php if($current_page_enacted_ordinances >= $total_pages_enacted_ordinances) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_enacted_ordinances=<?php echo $current_page_enacted_ordinances + 1; ?>&search_enacted_ordinances=<?php echo urlencode($search_query_enacted_ordinances); ?>&tab=enacted-ordinances" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

            <div class="tab-pane fade" id="enacted-resolutions" role="tabpanel" aria-labelledby="enacted-resolutions-tab">
                <h2 class="mt-4 mb-3">Enacted Resolutions</h2>
                <form method="GET" action="" class="mb-3">
                    <input type="hidden" name="tab" value="enacted-resolutions">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" name="search_enacted_resolutions" class="form-control" placeholder="Search by title or SP number" value="<?php echo isset($_GET['search_enacted_resolutions']) ? htmlspecialchars($_GET['search_enacted_resolutions']) : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <select name="category_enacted_resolutions" class="form-select">
                                <option value="">All Categories</option>
                                <option value="Agriculture" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Agriculture') ? 'selected' : ''; ?>>Agriculture</option>
                                <option value="Education" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Education') ? 'selected' : ''; ?>>Education</option>
                                <option value="Health" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Health') ? 'selected' : ''; ?>>Health</option>
                                <option value="Infrastructure" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Infrastructure') ? 'selected' : ''; ?>>Infrastructure</option>
                                <option value="Environment" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Environment') ? 'selected' : ''; ?>>Environment</option>
                                <option value="Social Services" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Social Services') ? 'selected' : ''; ?>>Social Services</option>
                                <option value="Economic Development" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Economic Development') ? 'selected' : ''; ?>>Economic Development</option>
                                <option value="Public Safety" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Public Safety') ? 'selected' : ''; ?>>Public Safety</option>
                                <option value="Transportation" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Transportation') ? 'selected' : ''; ?>>Transportation</option>
                                <option value="Finance" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                                <option value="Others" <?php echo (isset($_GET['category_enacted_resolutions']) && $_GET['category_enacted_resolutions'] === 'Others') ? 'selected' : ''; ?>>Others</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" type="submit">Filter</button>
                        </div>
                    </div>
                    <?php if ((isset($_GET['search_enacted_resolutions']) && !empty($_GET['search_enacted_resolutions'])) || (isset($_GET['category_enacted_resolutions']) && !empty($_GET['category_enacted_resolutions']))): ?>
                        <div class="mt-2">
                            <a href="?tab=enacted-resolutions&page=1" class="btn btn-sm btn-outline-danger">Clear All Filters</a>
                        </div>
                    <?php endif; ?>
                </form>
                
                <!-- Skeleton Loading for Enacted Resolutions -->
                <div class="skeleton-container" id="enacted-resolutions-skeleton">
                    <div class="table-responsive">
                        <table class="skeleton-table">
                            <thead class="skeleton-header">
                                <tr>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                    <th><div class="skeleton"></div></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for($i = 0; $i < 5; $i++): ?>
                                <tr class="skeleton-row">
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell title">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                    <td class="skeleton-cell introducers"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell date"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell status"><div class="skeleton"></div></td>
                                    <td class="skeleton-cell actions">
                                        <div class="skeleton"></div>
                                        <div class="skeleton"></div>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="table-responsive" id="enacted-resolutions-table">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>SP No.</th>
                                <th>Title</th>
                                <th>Introducers</th>
                                <th>Checking Remarks in Pending Measures</th>
                                <th>Checking Remarks in Approved Measures</th>
                                <th>Measure Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $current_page_enacted_resolutions = isset($_GET['page_enacted_resolutions']) ? (int)$_GET['page_enacted_resolutions'] : 1;
                            $search_query_enacted_resolutions = isset($_GET['search_enacted_resolutions']) ? htmlspecialchars($_GET['search_enacted_resolutions']) : '';
                            $category_filter_enacted_resolutions = isset($_GET['category_enacted_resolutions']) ? htmlspecialchars($_GET['category_enacted_resolutions']) : '';
                            $offset_enacted_resolutions = ($current_page_enacted_resolutions - 1) * $records_per_page;

                            $search_conditions_enacted_resolutions = "WHERE measure_type = 'Resolution' AND measure_status = 'Enacted'";
                            if (!empty($search_query_enacted_resolutions)) {
                                $search_terms_enacted_resolutions = explode(' ', $search_query_enacted_resolutions);
                                $search_conditions_array_enacted_resolutions = [];
                                foreach ($search_terms_enacted_resolutions as $term) {
                                    $search_conditions_array_enacted_resolutions[] = "(measure_title LIKE ? OR sp_no LIKE ?)";
                                }
                                $search_conditions_enacted_resolutions .= " AND (" . implode(" OR ", $search_conditions_array_enacted_resolutions) . ")";
                            }
                            // Fetch all records (category filter will be applied in PHP)
                            $sql_enacted_resolutions = "SELECT * FROM m9_similaritychecking " . $search_conditions_enacted_resolutions;
                            $stmt_enacted_resolutions = $lgu2_conn->prepare($sql_enacted_resolutions);
                            if ($stmt_enacted_resolutions === false) {
                                die("Error preparing statement for enacted resolutions: " . $lgu2_conn->error);
                            }
                            if (!empty($search_query_enacted_resolutions)) {
                                $bind_params_data_enacted_resolutions = [];
                                $types_data_enacted_resolutions = '';
                                foreach ($search_terms_enacted_resolutions as $term) {
                                    $types_data_enacted_resolutions .= 'ss';
                                    $bind_params_data_enacted_resolutions[] = "%" . $term . "%";
                                    $bind_params_data_enacted_resolutions[] = "%" . $term . "%";
                                }
                                $stmt_enacted_resolutions->bind_param($types_data_enacted_resolutions, ...$bind_params_data_enacted_resolutions);
                            }

                            if (!$stmt_enacted_resolutions->execute()) {
                                die("Error executing statement for enacted resolutions: " . $stmt_enacted_resolutions->error);
                            }
                            $result_enacted_resolutions = $stmt_enacted_resolutions->get_result();
                            
                            // Filter by intelligent category detection
                            $filtered_enacted_resolutions = [];
                            while ($row_temp = $result_enacted_resolutions->fetch_assoc()) {
                                $detected_category = detectMeasureCategory($row_temp['measure_title'], $row_temp['measure_content']);
                                
                                if (empty($category_filter_enacted_resolutions) || $detected_category === $category_filter_enacted_resolutions) {
                                    $filtered_enacted_resolutions[] = $row_temp;
                                }
                            }
                            
                            // Apply pagination to filtered results
                            $total_records_enacted_resolutions = count($filtered_enacted_resolutions);
                            $total_pages_enacted_resolutions = ceil($total_records_enacted_resolutions / $records_per_page);
                            $paginated_enacted_resolutions = array_slice($filtered_enacted_resolutions, $offset_enacted_resolutions, $records_per_page);
                            
                            if (count($paginated_enacted_resolutions) > 0) {
                                foreach ($paginated_enacted_resolutions as $row) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['sp_no'] ?? '') . "</td>";
                                    // Apply truncation to title with HTML entity decoding
                                    echo "<td>";
                                    $decodedTitle = html_entity_decode($row['measure_title'], ENT_QUOTES, 'UTF-8');
                                    echo "<strong>" . htmlspecialchars(substr($decodedTitle, 0, 50)) . "</strong>";
                                    if (strlen($decodedTitle) > 50) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    // Apply truncation to introducers with HTML entity decoding
                                    echo "<td>";
                                    $decodedIntroducers = html_entity_decode($row['introducers'], ENT_QUOTES, 'UTF-8');
                                    echo htmlspecialchars(substr($decodedIntroducers, 0, 30));
                                    if (strlen($decodedIntroducers) > 30) {
                                        echo "<span style='color: #6c757d;'>...</span>";
                                    }
                                    echo "</td>";
                                    echo "<td>" . htmlspecialchars($row['checking_remarks_pending'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['checking_remarks_approved'] ?? '') . "</td>";
                                    echo "<td>" . htmlspecialchars($row['measure_status']) . "</td>";                                   
                                    echo "<td>
                                        <button type='button' class='modal1 btn btn-primary btn-sm btn-action view-task-btn' 
                                            data-measure='" . htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') . "'
                                            onclick='viewMeasureEnhanced(JSON.parse(this.getAttribute(\"data-measure\")))'>VIEW</button>
                                        </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7'>No enacted resolutions found.</td></tr>";
                            }
                            $stmt_enacted_resolutions->close();
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-container">
                    <span>Page <?php echo $current_page_enacted_resolutions; ?> of <?php echo $total_pages_enacted_resolutions; ?></span>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php if($current_page_enacted_resolutions <= 1) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_enacted_resolutions=<?php echo $current_page_enacted_resolutions - 1; ?>&search_enacted_resolutions=<?php echo urlencode($search_query_enacted_resolutions); ?>&tab=enacted-resolutions" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages_enacted_resolutions; $i++): ?>
                                <li class="page-item <?php if($current_page_enacted_resolutions == $i) echo 'active'; ?>">
                                    <a class="page-link" href="?page_enacted_resolutions=<?php echo $i; ?>&search_enacted_resolutions=<?php echo urlencode($search_query_enacted_resolutions); ?>&tab=enacted-resolutions"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php if($current_page_enacted_resolutions >= $total_pages_enacted_resolutions) echo 'disabled'; ?>">
                                <a class="page-link" href="?page_enacted_resolutions=<?php echo $current_page_enacted_resolutions + 1; ?>&search_enacted_resolutions=<?php echo urlencode($search_query_enacted_resolutions); ?>&tab=enacted-resolutions" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

        </div>
    </div>
</div>

<?php
// ONLY CLOSE THE CONNECTION AT THE VERY END OF THE SCRIPT
if (isset($lgu2_conn) && $lgu2_conn->ping()) {
    $lgu2_conn->close();
}
?>

<!-- Enhanced View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2" style="background: linear-gradient(135deg, #004a8b 0%, #003866 100%); color: white;">
                <h5 class="modal-title" id="viewModalLabel" style="color: white; font-weight: 600;">Measure Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="modalBody" style="padding: 1.5rem;">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="ITSendModal" tabindex="-1" aria-labelledby="ITSendModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form action="" method="post">
                <div class="modal-header py-2" style="background: linear-gradient(135deg, #28a745 0%, #20893b 100%); color: white;">
                    <h5 class="modal-title" id="ITSendModalLabel" style="color: white; font-weight: 600;">Send Measure to Records</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 1.5rem;">
                    <div class="row mb-3">
                        <div class="col-12 mb-3">
                            <div class="send-title-header">
                                <label class="form-label fw-bold" style="color: #28a745; border-bottom: 2px solid #28a745; padding-bottom: 0.25rem; margin-bottom: 0.75rem; display: inline-block;">Title:</label>
                                <div class="send-title-controls">
                                    <button type="button" class="send-title-expand-btn" onclick="toggleSendTitle()">
                                        <i class="fas fa-expand" id="send-title-icon"></i> <span id="send-title-btn-text">Expand</span>
                                    </button>
                                    <button type="button" class="send-title-copy-btn" onclick="copySendTitle()" title="Copy Title">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="modal-send-title-text" style="background: #f8f9fa; padding: 1rem; border-radius: 6px; border-left: 4px solid #28a745; font-weight: 500; color: #1a365d; margin-top: 0.5rem; max-height: 100px; overflow: hidden; transition: max-height 0.3s ease;"></div>
                        </div>
                        <div class="col-12 mb-3">
                            <div class="send-introducers-header">
                                <label class="form-label fw-bold" style="color: #28a745; border-bottom: 2px solid #28a745; padding-bottom: 0.25rem; margin-bottom: 0.75rem; display: inline-block;">Introducers:</label>
                                <div class="send-introducers-controls">
                                    <button type="button" class="send-introducers-expand-btn" onclick="toggleSendIntroducers()">
                                        <i class="fas fa-expand" id="send-introducers-icon"></i> <span id="send-introducers-btn-text">Expand</span>
                                    </button>
                                    <button type="button" class="send-introducers-copy-btn" onclick="copySendIntroducers()" title="Copy Introducers">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="send-introducers-section" style="position: relative;">
                                <div id="modal-send-introducers-text" style="background: #f8f9fa; padding: 1rem; border-radius: 6px; border-left: 4px solid #28a745; margin-top: 0.5rem; line-height: 2; max-height: 80px; overflow: hidden; transition: max-height 0.3s ease; position: relative;"><div class="send-introducers-fade"></div></div>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="text-center">
                        <div class="mb-3">
                            <label for="checking_remarks_pending" class="form-label">Checking Remarks in Pending Measures</label>
                            <select class="form-select" id="checking_remarks_pending" name="checking_remarks_pending" required>
                                <option value="" disabled selected>Select an option</option>
                                <option value="Has a similar pending measure">Has a similar pending measure</option>
                                <option value="Has a similar pending measure but with different provisions in the text">Has a similar pending measure but with different provisions in the text</option>
                                <option value="Has no similar measure that is pending consideration">Has no similar measure that is pending consideration</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="checking_remarks_approved" class="form-label">Checking Remarks in Approved Measures</label>
                            <select class="form-select" id="checking_remarks_approved" name="checking_remarks_approved" required>
                                <option value="" disabled selected>Select an option</option>
                                <option value="Has a similar approved measure">Has a similar approved measure</option>
                                <option value="Has a similar approved measure but with different provisions in the text">Has a similar approved measure but with different provisions in the text</option>
                                <option value="Has no similar measure that is approved consideration">Has no similar measure that is approved consideration</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="checking_notes" class="form-label">Checking Notes</label>
                            <textarea class="form-control" id="checking_notes" name="checking_notes" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="checked_by" class="form-label visually-hidden">Checked By</label>
                            <input type="hidden" id="checked_by" name="checked_by" value="Research Section">
                        </div>
                        <div class="mb-3">
                            <label for="datetime_submitted" class="form-label visually-hidden">Date & Time Submitted</label>
                            <input type="hidden" class="form-control" id="datetime_submitted" name="datetime_submitted" required>
                        </div>
                    </div>
                    <input type="hidden" id="modal-send-id-hidden" name="m9_SC_ID">
                    <input type="hidden" id="modal-send-idcode-hidden" name="m9_SC_Code">
                    <input type="hidden" id="modal-send-date-hidden" name="date_created">
                    <input type="hidden" id="modal-send-type-hidden" name="measure_type">
                    <input type="hidden" id="modal-send-title-hidden" name="measure_title">
                    <input type="hidden" id="modal-send-content-hidden" name="measure_content">
                    <input type="hidden" id="modal-send-introducers-hidden" name="introducers">
                    <input type="hidden" id="modal-send-status-hidden" name="measure_status">
                    <input type="hidden" id="modal-send-category-hidden" name="category">
                    <input type="hidden" id="modal-send-sp_no-hidden" name="sp_no">
                    <input type="hidden" id="modal-send-year_cc-hidden" name="year_cc">
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="send_task" class="btn btn-success">Send Task</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2" style="background: linear-gradient(135deg, #004a8b 0%, #115272 100%); color: white;">
                <h5 class="modal-title" id="reviewModalLabel" style="color: white; font-weight: 600;">
                    <i class="fas fa-clipboard-check me-2"></i>Measure Review Progress
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <input type="hidden" id="review-measure-id">
                
                <!-- Review Progress Indicator -->
                <div class="review-progress-container mb-4">
                    <div class="review-progress-steps">
                        <div class="review-step" id="step-1st">
                            <div class="review-step-icon">
                                <i class="fas fa-circle-notch"></i>
                            </div>
                            <div class="review-step-label">1st Review</div>
                        </div>
                        <div class="review-step-connector"></div>
                        <div class="review-step" id="step-2nd">
                            <div class="review-step-icon">
                                <i class="fas fa-circle-notch"></i>
                            </div>
                            <div class="review-step-label">2nd Review</div>
                        </div>
                        <div class="review-step-connector"></div>
                        <div class="review-step" id="step-final">
                            <div class="review-step-icon">
                                <i class="fas fa-circle-notch"></i>
                            </div>
                            <div class="review-step-label">Final Review</div>
                        </div>
                    </div>
                </div>

                <!-- Review Sections -->
                <div class="review-sections">
                    <!-- 1st Review Section -->
                    <div class="review-section" id="section-1st-review">
                        <div class="review-section-header">
                            <h6 class="mb-0">
                                <i class="fas fa-file-alt me-2"></i>1st Review
                                <span class="review-status-badge" id="badge-1st"></span>
                            </h6>
                        </div>
                        <div class="review-section-body">
                            <div id="content-1st-review">
                                <div class="mb-3">
                                    <label for="notes-1st-review" class="form-label fw-bold">Review Notes <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="notes-1st-review" rows="4" placeholder="Enter your review notes here..."></textarea>
                                </div>
                                <button type="button" class="btn btn-primary" onclick="saveReview('1st_review')">
                                    <i class="fas fa-save me-1"></i>Save 1st Review
                                </button>
                            </div>
                            <div id="history-1st-review" style="display: none;">
                                <!-- History content will be loaded here -->
                            </div>
                        </div>
                    </div>

                    <!-- 2nd Review Section -->
                    <div class="review-section" id="section-2nd-review">
                        <div class="review-section-header">
                            <h6 class="mb-0">
                                <i class="fas fa-file-alt me-2"></i>2nd Review
                                <span class="review-status-badge" id="badge-2nd"></span>
                            </h6>
                        </div>
                        <div class="review-section-body">
                            <div id="content-2nd-review">
                                <div class="alert alert-info" role="alert">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Please complete 1st Review before proceeding to 2nd Review.
                                </div>
                                <div class="mb-3" style="display: none;" id="form-2nd-review">
                                    <label for="notes-2nd-review" class="form-label fw-bold">Review Notes <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="notes-2nd-review" rows="4" placeholder="Enter your review notes here..."></textarea>
                                </div>
                                <button type="button" class="btn btn-primary" onclick="saveReview('2nd_review')" style="display: none;" id="btn-2nd-review">
                                    <i class="fas fa-save me-1"></i>Save 2nd Review
                                </button>
                            </div>
                            <div id="history-2nd-review" style="display: none;">
                                <!-- History content will be loaded here -->
                            </div>
                        </div>
                    </div>

                    <!-- Final Review Section -->
                    <div class="review-section" id="section-final-review">
                        <div class="review-section-header">
                            <h6 class="mb-0">
                                <i class="fas fa-file-alt me-2"></i>Final Review
                                <span class="review-status-badge" id="badge-final"></span>
                            </h6>
                        </div>
                        <div class="review-section-body">
                            <div id="content-final-review">
                                <div class="alert alert-info" role="alert">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Please complete 1st Review and 2nd Review before proceeding to Final Review.
                                </div>
                                <div class="mb-3" style="display: none;" id="form-final-review">
                                    <label for="notes-final-review" class="form-label fw-bold">Review Notes <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="notes-final-review" rows="4" placeholder="Enter your final review notes here..."></textarea>
                                </div>
                                <button type="button" class="btn btn-success" onclick="saveReview('final_review')" style="display: none;" id="btn-final-review">
                                    <i class="fas fa-check-circle me-1"></i>Save Final Review
                                </button>
                            </div>
                            <div id="history-final-review" style="display: none;">
                                <!-- History content will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-danger" onclick="resetReviews()" id="reset-reviews-btn" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); border: none;">
                    <i class="fas fa-redo me-1"></i>Reset All Reviews
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Password Verification Modal -->
<div class="modal fade" id="passwordVerifyModal" tabindex="-1" aria-labelledby="passwordVerifyModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2" style="background: linear-gradient(135deg, #004a8b 0%, #115272 100%); color: white;">
                <h5 class="modal-title" id="passwordVerifyModalLabel" style="color: white; font-weight: 600;">
                    <i class="fas fa-lock me-2"></i>Password Verification Required
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="passwordModalCloseBtn"></button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <div class="alert alert-info" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Security Check:</strong> Please enter your login password to continue.
                </div>
                <form id="passwordVerifyForm">
                    <div class="mb-3">
                        <label for="verify_password" class="form-label fw-bold" style="color: #004a8b;">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="verify_password" name="verify_password" placeholder="Enter your password" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility()">
                                <i class="fas fa-eye" id="passwordToggleIcon"></i>
                            </button>
                        </div>
                        <div id="passwordError" class="text-danger mt-2" style="display: none;"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="passwordCancelBtn">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" id="verifyPasswordBtn" onclick="verifyPassword()" style="background: linear-gradient(135deg, #004a8b 0%, #115272 100%); border: none;">
                    <i class="fas fa-check me-1"></i>Verify & Continue
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk PDF Generation Modal -->
<div class="modal fade" id="bulkPdfModal" tabindex="-1" aria-labelledby="bulkPdfModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2" style="background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%); color: white;">
                <h5 class="modal-title" id="bulkPdfModalLabel" style="color: white; font-weight: 600;">
                    <i class="fas fa-file-pdf me-2"></i>Generate Bulk PDF Report
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <div class="alert alert-info" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Bulk PDF Generation:</strong> This will generate a PDF containing all filtered records.
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold" style="color: #dc3545;">Date Range:</label>
                        <div id="bulk-pdf-date-range" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa;"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold" style="color: #dc3545;">Total Records:</label>
                        <div id="bulk-pdf-total-records" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa;"></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12">
                        <label class="form-label fw-bold" style="color: #dc3545;">Records to be included:</label>
                        <div id="bulk-pdf-records-list" class="border rounded p-2" style="background-color: #f8f9fa; max-height: 300px; overflow-y: auto;">
                            <!-- Records will be listed here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" id="generateBulkPdfBtn" onclick="requestPasswordForBulkPdf()" style="background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%); border: none; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);">
                    <i class="fas fa-file-pdf me-1"></i>Generate PDF
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Retrieve Measure Modal -->
<div class="modal fade" id="retrieveModal" tabindex="-1" aria-labelledby="retrieveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2" style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: white;">
                <h5 class="modal-title" id="retrieveModalLabel" style="color: white; font-weight: 600;">
                    <i class="fas fa-undo me-2"></i>Retrieve Measure
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body" style="padding: 1.5rem;">
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Are you sure you want to retrieve this measure?</strong>
                    </div>
                    <p class="mb-3">This action will move the measure back to <strong>Incoming Task</strong> with status <strong>Draft</strong>.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold" style="color: #ff9800;">Measure Title:</label>
                        <div id="retrieve-measure-title" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa;"></div>
                    </div>
                    <input type="hidden" name="m9_SC_ID" id="retrieve-m9-sc-id">
                    <input type="hidden" name="retrieve_task" value="1">
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning" style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); border: none; box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3); color: white;">
                        <i class="fas fa-undo me-1"></i>Retrieve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- PDF Generation Modal -->
<div class="modal fade" id="pdfModal" tabindex="-1" aria-labelledby="pdfModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2" style="background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%); color: white;">
                <h5 class="modal-title" id="pdfModalLabel" style="color: white; font-weight: 600;">
                    <i class="fas fa-file-pdf me-2"></i>Generate PDF Document
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>PDF Preview:</strong> The following information will be included in your PDF document.
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold" style="color: #dc3545;">Date Created:</label>
                        <div id="pdf-date-created" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa;"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold" style="color: #dc3545;">Measure Type:</label>
                        <div id="pdf-measure-type" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa;"></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label fw-bold" style="color: #dc3545;">Measure Title:</label>
                        <div id="pdf-measure-title" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa; max-height: 100px; overflow-y: auto;"></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label fw-bold" style="color: #dc3545;">Introducers:</label>
                        <div id="pdf-introducers" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa; max-height: 80px; overflow-y: auto;"></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label fw-bold" style="color: #dc3545;">Measure Content:</label>
                        <div id="pdf-measure-content" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa; max-height: 200px; overflow-y: auto; white-space: pre-wrap;"></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label fw-bold" style="color: #dc3545;">Checking Remarks in Pending Measures:</label>
                        <div id="pdf-checking-remarks-pending" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa;"></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label fw-bold" style="color: #dc3545;">Checking Remarks in Approved Measures:</label>
                        <div id="pdf-checking-remarks-approved" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa;"></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label fw-bold" style="color: #dc3545;">Checking Notes:</label>
                        <div id="pdf-checking-notes" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa; max-height: 100px; overflow-y: auto; white-space: pre-wrap;"></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label fw-bold" style="color: #dc3545;">Date & Time Submitted:</label>
                        <div id="pdf-datetime-submitted" class="form-control-plaintext border rounded p-2" style="background-color: #f8f9fa;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" id="generatePdfBtn" onclick="generatePDF()" style="background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%); border: none; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);">
                    <i class="fas fa-file me-1"></i>Generate PDF
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Alert Modal -->
<div id="customAlertModal" class="custom-alert-modal">
    <div class="custom-alert-content">
        <div class="custom-alert-header">
            <span class="custom-alert-icon" id="customAlertIcon">ℹ️</span>
            <h3 class="custom-alert-title" id="customAlertTitle">Notice</h3>
        </div>
        <div class="custom-alert-body" id="customAlertMessage">
            Message goes here
        </div>
        <div class="custom-alert-actions">
            <button class="custom-alert-btn" id="customAlertButton" onclick="closeCustomAlert()">OK</button>
        </div>
    </div>
</div>

<script>
// Enhanced JavaScript with all advanced features

// Custom Alert Modal System
function showCustomAlert(message, type = 'info', title = null) {
    const modal = document.getElementById('customAlertModal');
    const icon = document.getElementById('customAlertIcon');
    const titleEl = document.getElementById('customAlertTitle');
    const messageEl = document.getElementById('customAlertMessage');
    const button = document.getElementById('customAlertButton');
    
    // Set icon and title based on type
    const config = {
        'info': { icon: 'ℹ️', title: 'Notice', btnClass: '' },
        'error': { icon: '❌', title: 'Error', btnClass: 'error' },
        'warning': { icon: '⚠️', title: 'Warning', btnClass: 'warning' },
        'success': { icon: '✓', title: 'Success', btnClass: 'success' }
    };
    
    const typeConfig = config[type] || config['info'];
    icon.textContent = typeConfig.icon;
    titleEl.textContent = title || typeConfig.title;
    messageEl.innerHTML = message;
    button.className = 'custom-alert-btn ' + typeConfig.btnClass;
    
    modal.style.display = 'flex';
}

function closeCustomAlert() {
    const modal = document.getElementById('customAlertModal');
    modal.style.display = 'none';
}

// Close modal when clicking outside
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('customAlertModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeCustomAlert();
            }
        });
    }
});

// Store current measure data
let currentMeasure = null;
// Reusable instance for the view modal to avoid multiple backdrops
let viewModalInstance = null;

// Function to decode HTML entities (like &quot; back to ")
function decodeHtmlEntities(text) {
    if (!text) return text;
    const div = document.createElement('div');
    div.innerHTML = text;
    return div.textContent || div.innerText || text;
}

// Function to escape HTML for safe display
function escapeHtml(text) {
    if (!text) return text;
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Enhanced view function with advanced modal
function viewMeasureEnhanced(measure) {
    try {
        // Validate measure data
        if (!measure || typeof measure !== 'object') {
            console.error('Invalid measure data:', measure);
            showCustomAlert('Invalid measure data. Please try again.', 'error');
            return;
        }
        
        currentMeasure = measure;
    
    
    // Comprehensive scroll reset
    const modal = document.getElementById('viewModal');
    
    document.body.scrollTop = 0;
    document.documentElement.scrollTop = 0;
    
    if (modal) {
        modal.scrollTop = 0;
    }
    
    window.scrollTo(0, 0);

    // Format introducers for better display
    let introducersHtml = '';
    if (measure.introducers) {
        let introducersText = decodeHtmlEntities(measure.introducers.trim());
        
        // Parse introducers - handle both new line-by-line format and old concatenated format
        const introducers = [];
        const lines = introducersText.split(/\r?\n/).filter(line => line.trim());
        
        // Check if ANY line has multiple titles (old concatenated format)
        const hasMultipleTitlesPerLine = lines.some(line => {
            const lineTitleCount = (line.match(/(Vice\s+Mayor|Mayor|Councilor|Hon\.|Dr\.|Atty\.)/gi) || []).length;
            return lineTitleCount > 1;
        });
        
        if (hasMultipleTitlesPerLine) {
            // Old format: multiple lines with concatenated titles
            lines.forEach((line, lineIndex) => {
                const lineTitleCount = (line.match(/(Vice\s+Mayor|Mayor|Councilor|Hon\.|Dr\.|Atty\.)/gi) || []).length;
                
                if (lineTitleCount > 1) {
                    // This line has multiple concatenated introducers
                    const splitPattern = /(Vice\s+Mayor|Mayor|Councilor|Hon\.|Dr\.|Atty\.)/g;
                    const parts = line.split(splitPattern);
                    
                    // Reconstruct introducers by combining titles with names
                    for (let i = 1; i < parts.length; i += 2) {
                        if (parts[i] && parts[i + 1]) {
                            const title = parts[i].trim();
                            let name = parts[i + 1].trim();
                            if (name) {
                                // Handle Vice Mayor specially to avoid splitting
                                if (title === 'Mayor' && i > 1 && parts[i - 1] && parts[i - 1].trim().endsWith('Vice')) {
                                    continue;
                                }
                                
                                // Clean up the name
                                name = name.replace(/\s*(Vice\s+Mayor|Mayor|Councilor|Hon\.|Dr\.|Atty\.).*$/i, '').trim();
                                name = name.replace(/,?\s*(Chairperson|Chair).*$/i, '').trim();
                                
                                if (name) {
                                    introducers.push(`${title} ${name}`.trim());
                                }
                            }
                        }
                    }
                } else if (lineTitleCount === 1) {
                    introducers.push(line.trim());
                }
            });
        } else {
            // New format: line-by-line
            lines.forEach(line => {
                const trimmedLine = line.trim();
                if (trimmedLine) {
                    introducers.push(trimmedLine);
                }
            });
        }
        
        // Generate HTML for introducers
        if (introducers.length > 0) {
            introducersHtml = introducers.map(introducer => 
                `<span class="introducer-item">${escapeHtml(introducer)}</span>`
            ).join('');
        } else {
            introducersHtml = escapeHtml(introducersText);
        }
    } else {
        introducersHtml = 'No introducers specified';
    }

    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = `
        <div class="measure-detail">
            <div class="title-header">
                <h3 style="margin: 0;">Title</h3>
                <div class="title-controls">
                    <button class="title-expand-btn" onclick="toggleViewTitle()">
                        <i class="fas fa-expand" id="view-title-icon"></i> <span id="view-title-btn-text">Expand</span>
                    </button>
                    <button class="title-copy-btn" onclick="copyTitle()" title="Copy Title">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
            <p id="view-title" style="max-height: 100px; overflow: hidden; transition: max-height 0.3s ease;">${escapeHtml(decodeHtmlEntities(measure.measure_title))}</p>
        </div>
        <div class="measure-detail">
            <div class="introducers-section">
                <div class="introducers-header">
                    <h3 style="margin: 0;">Introducers</h3>
                    <div class="introducers-controls">
                        <button class="introducers-expand-btn" onclick="toggleIntroducers()">
                            <i class="fas fa-expand" id="introducers-icon"></i> <span id="introducers-btn-text">Expand</span>
                        </button>
                        <button class="introducers-copy-btn" onclick="copyIntroducers()" title="Copy Introducers">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div id="view-introducers">${introducersHtml}<div class="introducers-fade"></div></div>
            </div>
        </div>
        <div class="measure-detail">
            <div class="measure-content-header">
                <h3 style="margin: 0;">Measure Content</h3>
                <div class="content-controls">
                    <button class="content-control-btn" onclick="expandContent()">
                        <i class="fas fa-expand"></i> Expand
                    </button>
                    <button class="content-control-btn content-copy-btn" onclick="copyContent()" title="Copy Content">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
            <div class="measure-content" id="view-content">${escapeHtml(decodeHtmlEntities(measure.measure_content))}</div>
        </div>
        ${measure.measure_status === 'Draft' || !measure.measure_status ? `
        <div class="measure-detail">
            <div class="file-preview-header">
                <h3 style="margin: 0;">Attached File</h3>
                <div class="file-controls">
                    <button class="file-control-btn file-download-btn" onclick="downloadMeasureFile()" title="Download File" ${!measure.measure_file ? 'disabled' : ''}>
                        <i class="fas fa-download"></i> Download
                    </button>
                </div>
            </div>
            <div class="file-preview-container" id="file-preview-container">
                ${generateFilePreview(measure.measure_file)}
            </div>
        </div>
        ` : ''}
    `;
    
    // Check if title section needs expand button (VIEW modal) - Force visible for now
    setTimeout(() => {
        const titleElement = document.getElementById('view-title');
        const titleExpandBtn = document.querySelector('.title-expand-btn');
        
        if (titleExpandBtn) {
            titleExpandBtn.style.display = 'flex'; // Always show for testing
        }
    }, 200);
    
    // Check if introducers section needs expand button (VIEW modal) - Force visible for now
    setTimeout(() => {
        const introducersDiv = document.getElementById('view-introducers');
        const expandBtn = document.querySelector('.introducers-expand-btn');
        
        if (expandBtn) {
            expandBtn.style.display = 'flex'; // Always show for testing
        }
    }, 200);
    
    // Check file availability if file exists
    if (measure.measure_file) {
        checkFileAvailability(measure.measure_file);
    }
    
    // Show modal using a single reusable Bootstrap instance to prevent stacked backdrops
    if (!viewModalInstance) {
        viewModalInstance = new bootstrap.Modal(modal);
    }
    viewModalInstance.show();
    
    } catch (error) {
        console.error('Error in viewMeasureEnhanced:', error);
        showCustomAlert('Error loading measure details: ' + error.message, 'error');
    }
}

function closeViewModal() {
    const modal = document.getElementById('viewModal');
    // Prefer the reusable instance; fall back to existing instance if already created elsewhere
    const modalInstance = viewModalInstance || bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
    if (modalInstance) {
        modalInstance.hide();
        viewModalInstance = modalInstance;
        
        // Clean up backdrop after transition
        setTimeout(() => {
            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }, 300); // align with Bootstrap fade duration
    }
}

// Toggle title expand/collapse in VIEW modal
function toggleViewTitle() {
    const titleElement = document.getElementById('view-title');
    const icon = document.getElementById('view-title-icon');
    const btnText = document.getElementById('view-title-btn-text');
    
    if (titleElement.classList.contains('expanded')) {
        titleElement.classList.remove('expanded');
        icon.className = 'fas fa-expand';
        btnText.textContent = 'Expand';
    } else {
        titleElement.classList.add('expanded');
        icon.className = 'fas fa-compress';
        btnText.textContent = 'Collapse';
    }
}

// Toggle introducers expand/collapse
function toggleIntroducers() {
    const introducersDiv = document.getElementById('view-introducers');
    const icon = document.getElementById('introducers-icon');
    const btnText = document.getElementById('introducers-btn-text');
    
    if (introducersDiv.classList.contains('expanded')) {
        introducersDiv.classList.remove('expanded');
        icon.className = 'fas fa-expand';
        btnText.textContent = 'Expand';
    } else {
        introducersDiv.classList.add('expanded');
        icon.className = 'fas fa-compress';
        btnText.textContent = 'Collapse';
    }
}

// Expand/collapse content
function expandContent() {
    const contentDiv = document.getElementById('view-content');
    const expandBtn = document.querySelector('.content-control-btn');
    
    if (contentDiv.classList.contains('expanded')) {
        contentDiv.classList.remove('expanded');
        expandBtn.innerHTML = '<i class="fas fa-expand"></i> Expand';
    } else {
        contentDiv.classList.add('expanded');
        expandBtn.innerHTML = '<i class="fas fa-compress"></i> Collapse';
    }
}

// Copy functions
function copyTitle() {
    const titleElement = document.getElementById('view-title');
    const text = titleElement.textContent;
    
    navigator.clipboard.writeText(text).then(() => {
        showCopySuccess('title-copy-btn');
    }).catch(err => {
        console.error('Failed to copy title: ', err);
    });
}

function copyContent() {
    const contentDiv = document.getElementById('view-content');
    const text = contentDiv.textContent;
    
    navigator.clipboard.writeText(text).then(() => {
        showCopySuccess('content-copy-btn');
    }).catch(err => {
        console.error('Failed to copy content: ', err);
    });
}

function copyIntroducers() {
    const introducersDiv = document.getElementById('view-introducers');
    
    // Get all introducer elements
    let introducerElements = introducersDiv.querySelectorAll('.introducer-item');
    
    let formattedText = '';
    if (introducerElements.length > 0) {
        const introducerNames = Array.from(introducerElements).map(element => element.textContent.trim());
        formattedText = introducerNames.join('\n');
    } else {
        // Fallback: get all text and try to parse it
        const allText = introducersDiv.textContent || introducersDiv.innerText || '';
        const lines = allText.split(/[,\n]/).map(line => line.trim()).filter(line => line.length > 0);
        formattedText = lines.join('\n');
    }
    
    if (!formattedText.trim()) {
        alert('No introducers found to copy');
        return;
    }
    
    navigator.clipboard.writeText(formattedText).then(() => {
        showCopySuccess('introducers-copy-btn');
    }).catch(err => {
        console.error('Failed to copy introducers: ', err);
    });
}

function showCopySuccess(buttonClass) {
    const copyBtn = document.querySelector(`.${buttonClass}`);
    const originalHtml = copyBtn.innerHTML;
    const originalBg = copyBtn.style.background;
    
    copyBtn.innerHTML = '<i class="fas fa-check"></i>';
    copyBtn.style.background = '#28a745';
    
    setTimeout(() => {
        copyBtn.innerHTML = originalHtml;
        copyBtn.style.background = originalBg;
    }, 2000);
}

// Alternative click handler for view buttons (fallback)
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('view-task-btn')) {
        const measureData = e.target.getAttribute('data-measure');
        if (measureData) {
            try {
                const measure = JSON.parse(measureData);
                viewMeasureEnhanced(measure);
            } catch (error) {
                console.error('Failed to parse measure data:', error);
                showCustomAlert('Failed to load measure details. Please try again.', 'error');
            }
        }
    }
});

// Send Modal Logic
document.addEventListener('DOMContentLoaded', function () {
    var ITSendModal = document.getElementById('ITSendModal');
    ITSendModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        
        try {
            // Reset form fields to default state
            ITSendModal.querySelector('#checking_remarks_pending').selectedIndex = 0;
            ITSendModal.querySelector('#checking_remarks_approved').selectedIndex = 0;
            ITSendModal.querySelector('#checking_notes').value = '';
            
            // Get JSON data from button
            var measureDataJson = button.getAttribute('data-measure-send');
            if (!measureDataJson) {
                console.error('No measure data found on send button');
                showCustomAlert('No measure data available. Please refresh the page.', 'error');
                return;
            }
            
            var measureData = JSON.parse(measureDataJson);
            
            // Extract data from JSON
            var id = measureData.m9_SC_ID || '';
            var idcode = measureData.m9_SC_Code || '';
            var date = measureData.date_created || '';
            var type = measureData.measure_type || '';
            var title = measureData.measure_title || '';
            var content = measureData.measure_content || '';
            var introducers = measureData.introducers || '';
            var status = measureData.measure_status || '';
            var datetime = measureData.datetime_submitted || '';
            var category = measureData.category || '';
            var sp_no = measureData.sp_no || '';
            var year_cc = measureData.year_cc || '';

            ITSendModal.querySelector('#modal-send-id-hidden').value = id;
            ITSendModal.querySelector('#modal-send-idcode-hidden').value = idcode;
            ITSendModal.querySelector('#modal-send-date-hidden').value = date;
            ITSendModal.querySelector('#modal-send-type-hidden').value = type;
            ITSendModal.querySelector('#modal-send-title-hidden').value = title;
            ITSendModal.querySelector('#modal-send-content-hidden').value = content;
            ITSendModal.querySelector('#modal-send-introducers-hidden').value = introducers;
            ITSendModal.querySelector('#modal-send-status-hidden').value = status;
            ITSendModal.querySelector('#modal-send-category-hidden').value = category;
            ITSendModal.querySelector('#modal-send-sp_no-hidden').value = sp_no;
            ITSendModal.querySelector('#modal-send-year_cc-hidden').value = year_cc;
            
            // Enhanced display for title and introducers in send modal
            ITSendModal.querySelector('#modal-send-title-text').textContent = title;
            
            // Format introducers for better display in send modal
            const introducersElement = ITSendModal.querySelector('#modal-send-introducers-text');
            if (introducers) {
                let introducersText = introducers.trim();
                
                // Simple HTML entity decoding for send modal
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = introducersText;
                introducersText = tempDiv.textContent || tempDiv.innerText || introducersText;
                
                const lines = introducersText.split(/\r?\n/).filter(line => line.trim());
                
                // Check if introducers need parsing
                const hasMultipleTitlesPerLine = lines.some(line => {
                    const lineTitleCount = (line.match(/(Vice\s+Mayor|Mayor|Councilor|Hon\.|Dr\.|Atty\.)/gi) || []).length;
                    return lineTitleCount > 1;
                });
                
                const parsedIntroducers = [];
                if (hasMultipleTitlesPerLine) {
                    lines.forEach((line) => {
                        const lineTitleCount = (line.match(/(Vice\s+Mayor|Mayor|Councilor|Hon\.|Dr\.|Atty\.)/gi) || []).length;
                        
                        if (lineTitleCount > 1) {
                            const splitPattern = /(Vice\s+Mayor|Mayor|Councilor|Hon\.|Dr\.|Atty\.)/g;
                            const parts = line.split(splitPattern);
                            
                            for (let i = 1; i < parts.length; i += 2) {
                                if (parts[i] && parts[i + 1]) {
                                    const title = parts[i].trim();
                                    let name = parts[i + 1].trim();
                                    if (name) {
                                        if (title === 'Mayor' && i > 1 && parts[i - 1] && parts[i - 1].trim().endsWith('Vice')) {
                                            continue;
                                        }
                                        name = name.replace(/\s*(Vice\s+Mayor|Mayor|Councilor|Hon\.|Dr\.|Atty\.).*$/i, '').trim();
                                        name = name.replace(/,?\s*(Chairperson|Chair).*$/i, '').trim();
                                        
                                        if (name) {
                                            parsedIntroducers.push(`${title} ${name}`.trim());
                                        }
                                    }
                                }
                            }
                        } else if (lineTitleCount === 1) {
                            parsedIntroducers.push(line.trim());
                        }
                    });
                } else {
                    lines.forEach(line => {
                        const trimmedLine = line.trim();
                        if (trimmedLine) {
                            parsedIntroducers.push(trimmedLine);
                        }
                    });
                }
                
                // Display introducers as badges in send modal
                if (parsedIntroducers.length > 0) {
                    // Simple HTML escaping for send modal
                    const introducersHtml = parsedIntroducers.map(introducer => {
                        const escapedIntroducer = introducer.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                        return `<span class="introducer-badge-send">${escapedIntroducer}</span>`;
                    }).join('');
                    introducersElement.innerHTML = introducersHtml;
                } else {
                    introducersElement.textContent = introducersText;
                }
            } else {
                introducersElement.textContent = 'No introducers specified';
            }
            
            // Check if title section needs expand button (SEND modal)
            setTimeout(() => {
                const titleElement = document.getElementById('modal-send-title-text');
                const titleExpandBtn = document.querySelector('.send-title-expand-btn');
                
                // Always show expand button for testing - you can adjust this logic later
                titleExpandBtn.style.display = 'flex';
                
                // Optional: Hide only if there's very little content
                if (titleElement.scrollHeight <= titleElement.clientHeight + 5) {
                    titleExpandBtn.style.display = 'none';
                }
            }, 200);
            
            // Check if introducers section needs expand button
            setTimeout(() => {
                const introducersDiv = document.getElementById('modal-send-introducers-text');
                const expandBtn = document.querySelector('.send-introducers-expand-btn');
                
                // Always show expand button for testing - you can adjust this logic later
                expandBtn.style.display = 'flex';
                
                // Optional: Hide only if there's very little content
                const badges = introducersDiv.querySelectorAll('.introducer-badge-send');
                if (badges.length <= 1) {
                    expandBtn.style.display = 'none';
                }
            }, 200);
            
        } catch (error) {
            console.error('Error parsing measure data for send modal:', error);
            showCustomAlert('Failed to load measure data for sending. Please try again.', 'error');
            return;
        }
        
        const now = new Date();
        const year = now.getFullYear();
        const month = (now.getMonth() + 1).toString().padStart(2, '0');
        const day = now.getDate().toString().padStart(2, '0');
        const hours = now.getHours().toString().padStart(2, '0');
        const minutes = now.getMinutes().toString().padStart(2, '0');
        
        const currentDatetime = `${year}-${month}-${day}T${hours}:${minutes}`;
        ITSendModal.querySelector('#datetime_submitted').value = currentDatetime;
    });
    
    // Alert handling
    const alertContainer = document.getElementById('alertContainer');
    const message = <?php echo json_encode(isset($_SESSION['message']) ? $_SESSION['message'] : null); ?>;

    if (message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${message.type} alert-dismissible fade show`;
        alertDiv.setAttribute('role', 'alert');
        alertDiv.textContent = message.text;

        const closeBtn = document.createElement('button');
        closeBtn.className = 'btn-close';
        closeBtn.setAttribute('type', 'button');
        closeBtn.setAttribute('data-bs-dismiss', 'alert');
        closeBtn.setAttribute('aria-label', 'Close');

        alertDiv.appendChild(closeBtn);
        alertContainer.appendChild(alertDiv);

        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alertDiv);
            bsAlert.close();
        }, 5000);
        
        <?php unset($_SESSION['message']); ?>
    }

    // Tab management
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'incoming';
    
    let tabButtonId;
    if (activeTab === 'pending-measures') {
        tabButtonId = 'pending-tab';
    } else if (activeTab === 'ordinances') {
        tabButtonId = 'ordinances-tab';
    } else if (activeTab === 'resolutions') {
        tabButtonId = 'resolutions-tab';
    } else if (activeTab === 'enacted-ordinances') {
        tabButtonId = 'enacted-ordinances-tab';
    } else if (activeTab === 'enacted-resolutions') {
        tabButtonId = 'enacted-resolutions-tab';
    } else {
        tabButtonId = `${activeTab}-tab`;
    }

    const tabButton = document.querySelector(`#${tabButtonId}`);
    if (tabButton) {
        const tab = new bootstrap.Tab(tabButton);
        tab.show();
    }
});

// Send Modal Functions
function toggleSendTitle() {
    const titleElement = document.getElementById('modal-send-title-text');
    const icon = document.getElementById('send-title-icon');
    const btnText = document.getElementById('send-title-btn-text');
    
    if (titleElement.classList.contains('expanded')) {
        titleElement.classList.remove('expanded');
        icon.className = 'fas fa-expand';
        btnText.textContent = 'Expand';
    } else {
        titleElement.classList.add('expanded');
        icon.className = 'fas fa-compress';
        btnText.textContent = 'Collapse';
    }
}

function toggleSendIntroducers() {
    const introducersDiv = document.getElementById('modal-send-introducers-text');
    const icon = document.getElementById('send-introducers-icon');
    const btnText = document.getElementById('send-introducers-btn-text');
    
    if (introducersDiv.classList.contains('expanded')) {
        introducersDiv.classList.remove('expanded');
        icon.className = 'fas fa-expand';
        btnText.textContent = 'Expand';
    } else {
        introducersDiv.classList.add('expanded');
        icon.className = 'fas fa-compress';
        btnText.textContent = 'Collapse';
    }
}

function copySendTitle() {
    const titleElement = document.getElementById('modal-send-title-text');
    const text = titleElement.textContent;
    
    navigator.clipboard.writeText(text).then(() => {
        showSendCopySuccess('send-title-copy-btn');
    }).catch(err => {
        console.error('Failed to copy title: ', err);
    });
}

function copySendIntroducers() {
    const introducersDiv = document.getElementById('modal-send-introducers-text');
    
    // Get all introducer elements
    let introducerElements = introducersDiv.querySelectorAll('.introducer-badge-send');
    
    let formattedText = '';
    if (introducerElements.length > 0) {
        const introducerNames = Array.from(introducerElements).map(element => element.textContent.trim());
        formattedText = introducerNames.join('\n');
    } else {
        // Fallback: get all text and try to parse it
        const allText = introducersDiv.textContent || introducersDiv.innerText || '';
        const lines = allText.split(/[,\n]/).map(line => line.trim()).filter(line => line.length > 0);
        formattedText = lines.join('\n');
    }
    
    if (!formattedText.trim()) {
        alert('No introducers found to copy');
        return;
    }
    
    navigator.clipboard.writeText(formattedText).then(() => {
        showSendCopySuccess('send-introducers-copy-btn');
    }).catch(err => {
        console.error('Failed to copy introducers: ', err);
    });
}

function showSendCopySuccess(buttonClass) {
    const copyBtn = document.querySelector(`.${buttonClass}`);
    const originalHtml = copyBtn.innerHTML;
    const originalBg = copyBtn.style.background;
    
    copyBtn.innerHTML = '<i class="fas fa-check"></i>';
    copyBtn.style.background = '#198754';
    
    setTimeout(() => {
        copyBtn.innerHTML = originalHtml;
        copyBtn.style.background = originalBg;
    }, 2000);
}

// Skeleton Loading Functions
function showSkeleton(containerId) {
    const skeleton = document.getElementById(containerId + '-skeleton');
    const content = document.getElementById(containerId + '-table');
    
    if (skeleton && content) {
        skeleton.classList.add('show');
        content.style.display = 'none';
    }
}

function hideSkeleton(containerId) {
    const skeleton = document.getElementById(containerId + '-skeleton');
    const content = document.getElementById(containerId + '-table');
    
    if (skeleton && content) {
        skeleton.classList.remove('show');
        content.style.display = 'block';
    }
}

function simulateLoading(containerId, minDuration = 1000) {
    showSkeleton(containerId);
    
    // Simulate loading time
    setTimeout(() => {
        hideSkeleton(containerId);
    }, minDuration);
}

// Initialize skeleton loading on page load
document.addEventListener('DOMContentLoaded', function() {
    // Show skeleton initially
    showSkeleton('incoming');
    
    // Hide skeleton after content loads (simulate network request)
    setTimeout(() => {
        hideSkeleton('incoming');
    }, 800);
});

// Show skeleton when switching tabs
document.addEventListener('shown.bs.tab', function(event) {
    const tabId = event.target.getAttribute('data-bs-target').replace('#', '');
    
    // Map tab IDs to container IDs
    const tabMapping = {
        'incoming': 'incoming',
        'pending-measures': 'pending-measures',
        'ordinances': 'ordinances',
        'resolutions': 'resolutions',
        'enacted-ordinances': 'enacted-ordinances',
        'enacted-resolutions': 'enacted-resolutions'
    };
    
    const containerId = tabMapping[tabId];
    if (containerId) {
        simulateLoading(containerId, 600);
    }
});

// Show skeleton during search operations
document.querySelector('form[method="GET"]')?.addEventListener('submit', function() {
    const activeTab = document.querySelector('.nav-link.active').getAttribute('data-bs-target').replace('#', '');
    const tabMapping = {
        'incoming': 'incoming',
        'pending-measures': 'pending-measures',
        'ordinances': 'ordinances',
        'resolutions': 'resolutions',
        'enacted-ordinances': 'enacted-ordinances',
        'enacted-resolutions': 'enacted-resolutions'
    };
    
    const containerId = tabMapping[activeTab];
    if (containerId) {
        showSkeleton(containerId);
    }
});

// Show skeleton during modal operations
function showModalSkeleton() {
    const overlay = document.createElement('div');
    overlay.className = 'modal-skeleton-overlay';
    overlay.innerHTML = `
        <div class="modal-skeleton">
            <div class="skeleton-modal-header">
                <div class="skeleton" style="width: 200px; height: 24px;"></div>
            </div>
            <div class="skeleton-modal-body">
                <div class="skeleton" style="width: 100%; height: 20px; margin-bottom: 15px;"></div>
                <div class="skeleton" style="width: 80%; height: 60px; margin-bottom: 15px;"></div>
                <div class="skeleton" style="width: 60%; height: 20px;"></div>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    
    setTimeout(() => {
        overlay.remove();
    }, 1000);
}

// PDF Generation Functions
let currentPdfMeasure = null;

function openPdfModal(measure) {
    try {
        // Validate measure data
        if (!measure || typeof measure !== 'object') {
            console.error('Invalid measure data:', measure);
            alert('Error: Invalid measure data');
            return;
        }
        
        currentPdfMeasure = measure;
        
        // Populate modal fields with measure data
        document.getElementById('pdf-date-created').textContent = measure.date_created || 'N/A';
        document.getElementById('pdf-measure-type').textContent = measure.measure_type || 'N/A';
        document.getElementById('pdf-measure-title').textContent = decodeHtmlEntities(measure.measure_title) || 'N/A';
        document.getElementById('pdf-introducers').textContent = decodeHtmlEntities(measure.introducers) || 'N/A';
        document.getElementById('pdf-measure-content').textContent = decodeHtmlEntities(measure.measure_content) || 'N/A';
        document.getElementById('pdf-checking-remarks-pending').textContent = decodeHtmlEntities(measure.checking_remarks_pending) || 'N/A';
        document.getElementById('pdf-checking-remarks-approved').textContent = decodeHtmlEntities(measure.checking_remarks_approved) || 'N/A';
        document.getElementById('pdf-checking-notes').textContent = decodeHtmlEntities(measure.checking_notes) || 'N/A';
        document.getElementById('pdf-datetime-submitted').textContent = measure.datetime_submitted || 'N/A';
        
        // Show the modal
        const pdfModal = new bootstrap.Modal(document.getElementById('pdfModal'));
        pdfModal.show();
        
    } catch (error) {
        console.error('Error opening PDF modal:', error);
        showCustomAlert('Error opening PDF preview. Please try again.', 'error');
    }
}

function generatePDF() {
    if (!currentPdfMeasure) {
        showCustomAlert('No measure data available. Please try again.', 'warning');
        return;
    }
    
    // Show loading state
    const generateBtn = document.getElementById('generatePdfBtn');
    const originalText = generateBtn.innerHTML;
    generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
    generateBtn.disabled = true;
    
    // Create form data and submit to PDF generation script
    const formData = new FormData();
    formData.append('action', 'generate_pdf');
    formData.append('measure_data', JSON.stringify(currentPdfMeasure));
    
    fetch('generate_pdf.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.blob();
    })
    .then(blob => {
        // Create download link
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `measure_${currentPdfMeasure.id || 'document'}_${new Date().getTime()}.pdf`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        // Close modal
        bootstrap.Modal.getInstance(document.getElementById('pdfModal')).hide();
        
        // Show success message
        alert('PDF generated successfully!');
    })
    .catch(error => {
        console.error('Error generating PDF:', error);
        alert('Error generating PDF. Please try again.');
    })
    .finally(() => {
        // Reset button state
        generateBtn.innerHTML = originalText;
        generateBtn.disabled = false;
    });
}

// Function to convert server file path to web URL
function convertToWebURL(filePath) {
    if (!filePath) {
        return '';
    }
    
    // Handle cases where the path might already be a URL
    if (filePath.indexOf('http') === 0) {
        return filePath;
    }
    
    // Served from this app, whatever server it runs on: keep the part from "uploads/" on
    const normalized = filePath.replace(/\\/g, '/');
    const marker = normalized.indexOf('uploads/');
    const relative = marker >= 0 ? normalized.slice(marker) : normalized.replace(/^\/+/, '');
    return (window.APP_BASE || '') + '/' + relative;
}

// File Preview Functions
function generateFilePreview(fileUrl) {
    if (!fileUrl) {
        return `<div class="no-file-message">
            <i class="fas fa-file-alt"></i>
            <p>No file attached to this measure.</p>
        </div>`;
    }
    
    // Convert file path to proper web URL
    const webUrl = convertToWebURL(fileUrl);
    
    // Get file extension and name
    const extension = fileUrl.split('.').pop().toLowerCase();
    const fileName = fileUrl.split('/').pop();
    
    // Use the converted web URL for display
    const fileDisplayUrl = webUrl;
    
    // Generate preview based on file type
    switch (extension) {
        case 'pdf':
            return `<div class="file-preview-item">
                <div class="file-info">
                    <span class="file-name"><i class="fas fa-file-pdf text-danger"></i> ${escapeHtml(fileName)}</span>
                    <span class="file-type badge bg-danger">PDF</span>
                </div>
            </div>`;
            
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'bmp':
        case 'webp':
            return `<div class="file-preview-item">
                <div class="file-info">
                    <span class="file-name"><i class="fas fa-file-image text-primary"></i> ${escapeHtml(fileName)}</span>
                    <span class="file-type badge bg-primary">IMAGE</span>
                </div>
            </div>`;
            
        case 'doc':
        case 'docx':
            return `<div class="file-preview-item">
                <div class="file-info">
                    <span class="file-name"><i class="fas fa-file-word text-primary"></i> ${escapeHtml(fileName)}</span>
                    <span class="file-type badge bg-primary">WORD</span>
                </div>
            </div>`;
            
        case 'txt':
            return `<div class="file-preview-item" style="display: none;">
                <div class="text-preview">
                    <div class="preview-placeholder">
                        <i class="fas fa-file-alt text-secondary" style="font-size: 4rem; margin-bottom: 1rem;"></i>
                        <p>Text Document Preview</p>
                        <p class="text-muted">Click download to view the text file</p>
                    </div>
                </div>
                <div class="file-info">
                    <span class="file-name"><i class="fas fa-file-alt text-secondary"></i> ${escapeHtml(fileName)}</span>
                    <span class="file-type badge bg-secondary">TEXT</span>
                </div>
            </div>`;
            
        default:
            return `<div class="file-preview-item" style="display: none;">
                <div class="unknown-preview">
                    <div class="preview-placeholder">
                        <i class="fas fa-file text-muted" style="font-size: 4rem; margin-bottom: 1rem;"></i>
                        <p>File Preview Not Available</p>
                        <p class="text-muted">Click download to view the file</p>
                    </div>
                </div>
                <div class="file-info">
                    <span class="file-name"><i class="fas fa-file text-muted"></i> ${escapeHtml(fileName)}</span>
                    <span class="file-type badge bg-secondary">${extension.toUpperCase()}</span>
                </div>
            </div>`;
    }
}


function checkFileAvailability(fileName) {
    // Convert to web URL
    const webUrl = convertToWebURL(fileName);
    
    // Simply enable download without checking availability to avoid CORS errors
    const downloadBtn = document.querySelector('.file-download-btn');
    
    if (downloadBtn) {
        downloadBtn.disabled = false;
        downloadBtn.title = 'Download File';
        downloadBtn.setAttribute('data-file-url', webUrl);
    }
}


function downloadMeasureFile() {
    if (!currentMeasure || !currentMeasure.measure_file) {
        showCustomAlert('No file available for download.', 'warning');
        return;
    }
    
    // Check if we have a stored working URL from the availability check
    const downloadBtn = document.querySelector('.file-download-btn');
    let downloadUrl = downloadBtn ? downloadBtn.getAttribute('data-file-url') : null;
    
    // If no stored URL, convert the file path
    if (!downloadUrl) {
        downloadUrl = convertToWebURL(currentMeasure.measure_file);
    }
    
    
    // Try direct download first
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = currentMeasure.measure_file.split('/').pop();
    link.target = '_blank';
    
    // Add error handling for the download
    link.onerror = function() {
        // If direct download fails, try opening in new window
        window.open(downloadUrl, '_blank');
    };
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ==========================================
// PASSWORD VERIFICATION FUNCTIONS
// ==========================================
let pendingPdfAction = null; // 'single' or 'bulk'
let pendingPdfMeasure = null;

function togglePasswordVisibility() {
    const passwordInput = document.getElementById('verify_password');
    const icon = document.getElementById('passwordToggleIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        passwordInput.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

// toggleUnlockPasswordVisibility removed - handled by system-lock.js

function requestPasswordForSinglePdf(measure) {
    pendingPdfAction = 'single';
    pendingPdfMeasure = measure;
    
    // Close PDF modal first
    const pdfModal = bootstrap.Modal.getInstance(document.getElementById('pdfModal'));
    if (pdfModal) {
        pdfModal.hide();
    }
    
    // Reset password form
    document.getElementById('verify_password').value = '';
    document.getElementById('passwordError').style.display = 'none';
    
    // Show password modal
    setTimeout(() => {
        const passwordModal = new bootstrap.Modal(document.getElementById('passwordVerifyModal'));
        passwordModal.show();
    }, 300);
}

function requestPasswordForBulkPdf() {
    pendingPdfAction = 'bulk';
    
    // Close bulk PDF modal first
    const bulkPdfModal = bootstrap.Modal.getInstance(document.getElementById('bulkPdfModal'));
    if (bulkPdfModal) {
        bulkPdfModal.hide();
    }
    
    // Reset password form
    document.getElementById('verify_password').value = '';
    document.getElementById('passwordError').style.display = 'none';
    
    // Show password modal
    setTimeout(() => {
        const passwordModal = new bootstrap.Modal(document.getElementById('passwordVerifyModal'));
        passwordModal.show();
    }, 300);
}

function verifyPassword() {
    const password = document.getElementById('verify_password').value;
    const errorDiv = document.getElementById('passwordError');
    const verifyBtn = document.getElementById('verifyPasswordBtn');
    
    if (!password) {
        errorDiv.textContent = 'Please enter your password.';
        errorDiv.style.display = 'block';
        return;
    }
    
    // Show loading state
    verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Verifying...';
    verifyBtn.disabled = true;
    
    // Send password verification request
    const formData = new FormData();
    formData.append('action', 'verify_password');
    formData.append('password', password);
    
    fetch('verify_password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Check if response is ok
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        // Get response as text first to check for errors
        return response.text();
    })
    .then(text => {
        // Try to parse as JSON
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Response text:', text);
            throw new Error('Invalid response from server');
        }
        
        if (data.success) {
            // Password verified, close modal and proceed
            const passwordModal = bootstrap.Modal.getInstance(document.getElementById('passwordVerifyModal'));
            passwordModal.hide();
            
            // Proceed with the pending action
            setTimeout(() => {
                if (pendingPdfAction === 'single') {
                    proceedWithSinglePdf();
                } else if (pendingPdfAction === 'bulk') {
                    proceedWithBulkPdf();
                } else if (pendingPdfAction === 'unlock') {
                    // System unlock handled separately
                }
            }, 300);
        } else {
            errorDiv.textContent = data.message || 'Invalid password. Please try again.';
            errorDiv.style.display = 'block';
        }
    })
    .catch(error => {
        console.error('Error verifying password:', error);
        errorDiv.textContent = 'An error occurred. Please try again.';
        errorDiv.style.display = 'block';
    })
    .finally(() => {
        verifyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Verify & Continue';
        verifyBtn.disabled = false;
    });
}

function proceedWithSinglePdf() {
    if (!pendingPdfMeasure) {
        showCustomAlert('No measure data available. Please try again.', 'warning');
        return;
    }
    
    // Show loading state
    const generateBtn = document.getElementById('generatePdfBtn');
    if (generateBtn) {
        generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
        generateBtn.disabled = true;
    }
    
    // Create form data and submit to PDF generation script
    const formData = new FormData();
    formData.append('action', 'generate_pdf');
    formData.append('measure_data', JSON.stringify(pendingPdfMeasure));
    
    fetch('generate_pdf.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.blob();
    })
    .then(blob => {
        // Create download link
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `measure_${pendingPdfMeasure.m9_SC_ID || 'document'}_${new Date().getTime()}.pdf`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        // Show success message
        alert('PDF generated successfully!');
    })
    .catch(error => {
        console.error('Error generating PDF:', error);
        alert('Error generating PDF. Please try again.');
    })
    .finally(() => {
        if (generateBtn) {
            generateBtn.innerHTML = '<i class="fas fa-file me-1"></i>Generate PDF';
            generateBtn.disabled = false;
        }
        pendingPdfMeasure = null;
        pendingPdfAction = null;
    });
}

function proceedWithBulkPdf() {
    if (!allPendingMeasures || allPendingMeasures.length === 0) {
        showCustomAlert('No measures available for PDF generation.', 'warning');
        return;
    }
    
    // Show loading state
    const generateBtn = document.getElementById('generateBulkPdfBtn');
    if (generateBtn) {
        generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
        generateBtn.disabled = true;
    }
    
    // Create form data and submit to PDF generation script
    const formData = new FormData();
    formData.append('action', 'generate_bulk_pdf');
    formData.append('measures_data', JSON.stringify(allPendingMeasures));
    formData.append('date_from', currentDateFilter.from);
    formData.append('date_to', currentDateFilter.to);
    
    fetch('generate_pdf.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.blob();
    })
    .then(blob => {
        // Create download link
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        const dateStr = currentDateFilter.from && currentDateFilter.to 
            ? `${currentDateFilter.from}_to_${currentDateFilter.to}` 
            : new Date().toISOString().split('T')[0];
        a.href = url;
        a.download = `bulk_measures_report_${dateStr}.pdf`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        // Show success message
        showCustomAlert('Bulk PDF generated successfully!', 'success');
    })
    .catch(error => {
        console.error('Error generating bulk PDF:', error);
        showCustomAlert('Error generating bulk PDF. Please try again.', 'error');
    })
    .finally(() => {
        if (generateBtn) {
            generateBtn.innerHTML = '<i class="fas fa-file-pdf me-1"></i>Generate PDF';
            generateBtn.disabled = false;
        }
        pendingPdfAction = null;
    });
}

// ==========================================
// RETRIEVE MODAL FUNCTION
// ==========================================
function openRetrieveModal(measureId, measureTitle) {
    // Decode HTML entities in the title
    const decodedTitle = decodeHtmlEntities(measureTitle);
    
    // Set the measure ID and title in the modal
    document.getElementById('retrieve-m9-sc-id').value = measureId;
    document.getElementById('retrieve-measure-title').textContent = decodedTitle;
    
    // Show the modal
    const retrieveModal = new bootstrap.Modal(document.getElementById('retrieveModal'));
    retrieveModal.show();
}

// ==========================================
// BULK PDF MODAL FUNCTIONS
// ==========================================
function openBulkPdfModal() {
    if (!allPendingMeasures || allPendingMeasures.length === 0) {
        showCustomAlert('No records found to generate PDF. Please adjust your filters.', 'warning');
        return;
    }
    
    // Populate date range
    let dateRangeText = 'All dates';
    if (currentDateFilter.from && currentDateFilter.to) {
        dateRangeText = `${currentDateFilter.from} to ${currentDateFilter.to}`;
    } else if (currentDateFilter.from) {
        dateRangeText = `From ${currentDateFilter.from}`;
    } else if (currentDateFilter.to) {
        dateRangeText = `Until ${currentDateFilter.to}`;
    }
    document.getElementById('bulk-pdf-date-range').textContent = dateRangeText;
    
    // Populate total records
    document.getElementById('bulk-pdf-total-records').textContent = allPendingMeasures.length + ' record(s)';
    
    // Populate records list
    const recordsList = document.getElementById('bulk-pdf-records-list');
    let recordsHtml = '<table class="table table-sm table-striped mb-0"><thead><tr><th>#</th><th>Date</th><th>Title</th><th>Type</th></tr></thead><tbody>';
    
    allPendingMeasures.forEach((measure, index) => {
        const title = decodeHtmlEntities(measure.measure_title || '');
        const truncatedTitle = title.length > 50 ? title.substring(0, 50) + '...' : title;
        recordsHtml += `<tr>
            <td>${index + 1}</td>
            <td>${measure.date_created || 'N/A'}</td>
            <td>${escapeHtml(truncatedTitle)}</td>
            <td>${measure.measure_type || 'N/A'}</td>
        </tr>`;
    });
    
    recordsHtml += '</tbody></table>';
    recordsList.innerHTML = recordsHtml;
    
    // Show the modal
    const bulkPdfModal = new bootstrap.Modal(document.getElementById('bulkPdfModal'));
    bulkPdfModal.show();
}

// ==========================================
// SYSTEM LOCK FUNCTIONS
// ==========================================
// System lock is now handled by /assets/js/system-lock.js
// No custom functions needed here

// ==========================================
// UPDATED PDF MODAL FUNCTION (with password verification)
// ==========================================
// Override the original openPdfModal to require password
const originalOpenPdfModal = openPdfModal;
openPdfModal = function(measure) {
    try {
        // Validate measure data
        if (!measure || typeof measure !== 'object') {
            console.error('Invalid measure data:', measure);
            alert('Error: Invalid measure data');
            return;
        }
        
        currentPdfMeasure = measure;
        pendingPdfMeasure = measure;
        
        // Populate modal fields with measure data
        document.getElementById('pdf-date-created').textContent = measure.date_created || 'N/A';
        document.getElementById('pdf-measure-type').textContent = measure.measure_type || 'N/A';
        document.getElementById('pdf-measure-title').textContent = decodeHtmlEntities(measure.measure_title) || 'N/A';
        document.getElementById('pdf-introducers').textContent = decodeHtmlEntities(measure.introducers) || 'N/A';
        document.getElementById('pdf-measure-content').textContent = decodeHtmlEntities(measure.measure_content) || 'N/A';
        document.getElementById('pdf-checking-remarks-pending').textContent = decodeHtmlEntities(measure.checking_remarks_pending) || 'N/A';
        document.getElementById('pdf-checking-remarks-approved').textContent = decodeHtmlEntities(measure.checking_remarks_approved) || 'N/A';
        document.getElementById('pdf-checking-notes').textContent = decodeHtmlEntities(measure.checking_notes) || 'N/A';
        document.getElementById('pdf-datetime-submitted').textContent = measure.datetime_submitted || 'N/A';
        
        // Show the modal
        const pdfModal = new bootstrap.Modal(document.getElementById('pdfModal'));
        pdfModal.show();
        
    } catch (error) {
        console.error('Error opening PDF modal:', error);
        showCustomAlert('Error opening PDF preview. Please try again.', 'error');
    }
};

// Override generatePDF to require password verification
generatePDF = function() {
    if (!currentPdfMeasure) {
        showCustomAlert('No measure data available. Please try again.', 'warning');
        return;
    }
    
    // Request password verification before generating PDF
    requestPasswordForSinglePdf(currentPdfMeasure);
};

// Review Modal Functions
function openReviewModal(measureId) {
    document.getElementById('review-measure-id').value = measureId;
    
    // Reset modal state
    resetReviewModal();
    
    // Load review history
    loadReviewHistory(measureId);
    
    // Show modal
    const reviewModal = new bootstrap.Modal(document.getElementById('reviewModal'));
    reviewModal.show();
}

function resetReviewModal() {
    // Reset all form fields
    document.getElementById('notes-1st-review').value = '';
    document.getElementById('notes-2nd-review').value = '';
    document.getElementById('notes-final-review').value = '';
    
    // Reset progress steps
    document.querySelectorAll('.review-step').forEach(step => {
        step.classList.remove('completed', 'active');
    });
    
    // Reset badges
    document.querySelectorAll('.review-status-badge').forEach(badge => {
        badge.textContent = '';
        badge.className = 'review-status-badge';
    });
    
    // Hide all history sections
    document.getElementById('history-1st-review').style.display = 'none';
    document.getElementById('history-2nd-review').style.display = 'none';
    document.getElementById('history-final-review').style.display = 'none';
    
    // Show all content sections
    document.getElementById('content-1st-review').style.display = 'block';
    document.getElementById('content-2nd-review').style.display = 'block';
    document.getElementById('content-final-review').style.display = 'block';
}

function loadReviewHistory(measureId) {
    fetch(`save_review.php?m9_SC_ID=${measureId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateReviewUI(data.reviews, data.is_completed);
                
                // Update the icon in the table if completed
                if (data.is_completed) {
                    const icon = document.getElementById(`review-icon-${measureId}`);
                    if (icon) {
                        icon.classList.add('completed');
                    }
                }
            } else {
                console.error('Failed to load review history:', data.message);
            }
        })
        .catch(error => {
            console.error('Error loading review history:', error);
        });
}

function updateReviewUI(reviews, isCompleted) {
    const reviewStages = {
        '1st_review': { step: 'step-1st', badge: 'badge-1st', content: 'content-1st-review', history: 'history-1st-review', form: 'form-1st-review', btn: 'btn-1st-review' },
        '2nd_review': { step: 'step-2nd', badge: 'badge-2nd', content: 'content-2nd-review', history: 'history-2nd-review', form: 'form-2nd-review', btn: 'btn-2nd-review' },
        'final_review': { step: 'step-final', badge: 'badge-final', content: 'content-final-review', history: 'history-final-review', form: 'form-final-review', btn: 'btn-final-review' }
    };
    
    let completedCount = 0;
    
    reviews.forEach(review => {
        const stage = reviewStages[review.review_stage];
        if (!stage) return;
        
        completedCount++;
        
        // Mark step as completed
        const stepElement = document.getElementById(stage.step);
        if (stepElement) {
            stepElement.classList.add('completed');
            stepElement.querySelector('.review-step-icon i').className = 'fas fa-check-circle';
        }
        
        // Update badge
        const badgeElement = document.getElementById(stage.badge);
        if (badgeElement) {
            badgeElement.textContent = 'Completed';
            badgeElement.classList.add('completed');
        }
        
        // Hide content form, show history
        const contentElement = document.getElementById(stage.content);
        const historyElement = document.getElementById(stage.history);
        
        if (contentElement) contentElement.style.display = 'none';
        if (historyElement) {
            historyElement.style.display = 'block';
            historyElement.innerHTML = `
                <div class="review-history-item">
                    <div class="review-history-meta">
                        <span><i class="fas fa-user me-1"></i><strong>${review.reviewed_by}</strong></span>
                        <span><i class="fas fa-clock me-1"></i>${formatDateTime(review.reviewed_at)}</span>
                    </div>
                    <div class="review-history-notes">${review.review_notes}</div>
                </div>
            `;
        }
    });
    
    // Enable next stage if previous is completed
    if (completedCount >= 1) {
        // Enable 2nd review
        const step2nd = document.getElementById('step-2nd');
        if (step2nd && !step2nd.classList.contains('completed')) {
            step2nd.classList.add('active');
        }
        
        const form2nd = document.getElementById('form-2nd-review');
        const btn2nd = document.getElementById('btn-2nd-review');
        if (form2nd) form2nd.style.display = 'block';
        if (btn2nd) btn2nd.style.display = 'inline-block';
        
        // Hide info alert for 2nd review
        const content2nd = document.getElementById('content-2nd-review');
        if (content2nd) {
            const alert = content2nd.querySelector('.alert');
            if (alert) alert.style.display = 'none';
        }
    }
    
    if (completedCount >= 2) {
        // Enable final review
        const stepFinal = document.getElementById('step-final');
        if (stepFinal && !stepFinal.classList.contains('completed')) {
            stepFinal.classList.add('active');
        }
        
        const formFinal = document.getElementById('form-final-review');
        const btnFinal = document.getElementById('btn-final-review');
        if (formFinal) formFinal.style.display = 'block';
        if (btnFinal) btnFinal.style.display = 'inline-block';
        
        // Hide info alert for final review
        const contentFinal = document.getElementById('content-final-review');
        if (contentFinal) {
            const alert = contentFinal.querySelector('.alert');
            if (alert) alert.style.display = 'none';
        }
    }
    
    // Set active state for current stage
    if (completedCount === 0) {
        document.getElementById('step-1st').classList.add('active');
    }
}

function saveReview(reviewStage) {
    const measureId = document.getElementById('review-measure-id').value;
    let notesField;
    
    if (reviewStage === '1st_review') {
        notesField = document.getElementById('notes-1st-review');
    } else if (reviewStage === '2nd_review') {
        notesField = document.getElementById('notes-2nd-review');
    } else if (reviewStage === 'final_review') {
        notesField = document.getElementById('notes-final-review');
    }
    
    const reviewNotes = notesField.value.trim();
    
    if (!reviewNotes) {
        showCustomAlert('Please enter review notes before saving.', 'warning');
        notesField.focus();
        return;
    }
    
    // Show loading state
    const btnId = reviewStage === '1st_review' ? null : (reviewStage === '2nd_review' ? 'btn-2nd-review' : 'btn-final-review');
    let originalBtnText = '';
    let btn = null;
    
    if (btnId) {
        btn = document.getElementById(btnId);
    } else {
        btn = event.target;
    }
    
    if (btn) {
        originalBtnText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
        btn.disabled = true;
    }
    
    // Send save request
    const payload = {
        m9_SC_ID: parseInt(measureId),
        review_stage: reviewStage,
        review_notes: reviewNotes
    };
    
    fetch('save_review.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showCustomAlert(`${reviewStage.replace('_', ' ').toUpperCase()} saved successfully!`, 'success');
            
            // Clear the notes field
            notesField.value = '';
            
            // Reload review history to update UI
            loadReviewHistory(measureId);
            
            // If final review is completed, update the icon
            if (reviewStage === 'final_review') {
                const icon = document.getElementById(`review-icon-${measureId}`);
                if (icon) {
                    icon.classList.add('completed');
                }
            }
        } else {
            showCustomAlert(data.message || 'Failed to save review.', 'error');
        }
    })
    .catch(error => {
        console.error('Error saving review:', error);
        showCustomAlert('An error occurred while saving the review.', 'error');
    })
    .finally(() => {
        // Restore button state
        if (btn) {
            btn.innerHTML = originalBtnText;
            btn.disabled = false;
        }
    });
}

function formatDateTime(dateTimeStr) {
    const date = new Date(dateTimeStr);
    const options = { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric', 
        hour: '2-digit', 
        minute: '2-digit',
        hour12: true 
    };
    return date.toLocaleString('en-US', options);
}

// Load review status on page load for all measures
document.addEventListener('DOMContentLoaded', function() {
    // Get all review icons and check their status
    const reviewIcons = document.querySelectorAll('.review-icon-btn');
    reviewIcons.forEach(btn => {
        const measureId = btn.getAttribute('data-measure-id');
        if (measureId) {
            fetch(`save_review.php?m9_SC_ID=${measureId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.is_completed) {
                        const icon = document.getElementById(`review-icon-${measureId}`);
                        if (icon) {
                            icon.classList.add('completed');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error checking review status:', error);
                });
        }
    });
});

// Reset Reviews Function
function resetReviews() {
    const measureId = document.getElementById('review-measure-id').value;
    
    if (!measureId) {
        showCustomAlert('No measure selected.', 'error');
        return;
    }
    
    // Show confirmation dialog using custom alert
    if (confirm('⚠️ WARNING: This will permanently delete ALL reviews (1st, 2nd, and Final) for this measure.\n\nThis action cannot be undone. Are you sure you want to reset all reviews?')) {
        
        // Show loading state on button
        const resetBtn = document.getElementById('reset-reviews-btn');
        const originalBtnText = resetBtn.innerHTML;
        resetBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Resetting...';
        resetBtn.disabled = true;
        
        // Send DELETE request
        fetch('save_review.php', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `m9_SC_ID=${measureId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showCustomAlert('All reviews have been reset successfully!', 'success');
                
                // Reset the modal UI
                resetReviewModal();
                
                // Reload review history (should be empty now)
                loadReviewHistory(measureId);
                
                // Update the icon in the table to remove completed status
                const icon = document.getElementById(`review-icon-${measureId}`);
                if (icon) {
                    icon.classList.remove('completed');
                }
                
                // Close the modal after a short delay
                setTimeout(() => {
                    const reviewModal = bootstrap.Modal.getInstance(document.getElementById('reviewModal'));
                    if (reviewModal) {
                        reviewModal.hide();
                    }
                }, 1500);
            } else {
                showCustomAlert(data.message || 'Failed to reset reviews.', 'error');
            }
        })
        .catch(error => {
            console.error('Error resetting reviews:', error);
            showCustomAlert('An error occurred while resetting reviews.', 'error');
        })
        .finally(() => {
            // Restore button state
            resetBtn.innerHTML = originalBtnText;
            resetBtn.disabled = false;
        });
    }
}
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<!-- System Lock JavaScript -->
<script src="<?= e(url('assets/js/system-lock.js')) ?>"></script>

</body>
</html>
