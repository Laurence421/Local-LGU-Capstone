<?php
/**
 * Fill the PORTFOLIO DEMO database with realistic, made-up sample data. Command line only.
 *
 *   php tools/migrate.php        (creates the tables)
 *   php tools/seed_demo.php      (replaces their contents with sample data)
 *
 * Refuses to run unless DEMO_MODE=true in .env, because it empties the tables it fills.
 * Every name, email and measure below is fictional.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

if (!DEMO_MODE) {
    fwrite(STDERR, "Refusing to seed: set DEMO_MODE=true in .env (and point DB_NAME at a demo database).\n");
    exit(1);
}

$conn = db_connect();
if ($conn->connect_errno) {
    fwrite(STDERR, "Cannot connect to database: {$conn->connect_error}\n");
    exit(1);
}
mt_srand(2026); // same data on every run

function insert($conn, $table, array $row)
{
    $cols = array_keys($row);
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("$table: {$conn->error}");
    }
    $values = array_values($row);
    $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    $stmt->execute();
    return $conn->insert_id;
}

function pick(array $items)
{
    return $items[mt_rand(0, count($items) - 1)];
}

// ---------------------------------------------------------------- tables
$records = env('RECORDS_DB_NAME', env('DB_NAME'));
$conn->query("CREATE TABLE IF NOT EXISTS `$records`.`m6_measuredocketing` (
    m6_MD_ID INT AUTO_INCREMENT PRIMARY KEY, m6_MD_Code VARCHAR(30), m8_SC_ID INT, m8_SC_Code INT,
    m9_SC_ID INT, m9_SC_Code VARCHAR(30), date_created DATE, measure_type VARCHAR(30), measure_title VARCHAR(255),
    measure_content LONGTEXT, introducers VARCHAR(255), measure_status VARCHAR(40), checking_remarks VARCHAR(255),
    checking_notes VARCHAR(255), checked_by VARCHAR(255), datetime_submitted DATETIME, docket_no VARCHAR(255),
    MFL_Name VARCHAR(255), MFL_Feedback VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS `$records`.`m6_document_tracking` (
    tracking_id INT AUTO_INCREMENT PRIMARY KEY, measure_id INT, remarks TEXT, date_sent DATETIME, date_received DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("SET FOREIGN_KEY_CHECKS = 0");
foreach (['password_resets', 'login_attempts', 'sso_used_tokens', 'audit_logs', 'm9_measure_reviews', 'm6_measuredocketing_fromresearch',
          'm9_similaritychecking', 'm1_measurecreation', 'm1_measurecreation_copy', 'document_requests', 'research_inbox',
          'research_measure', 'users'] as $table) {
    $conn->query("TRUNCATE TABLE `$table`");
}
$conn->query("TRUNCATE TABLE `$records`.`m6_measuredocketing`");
$conn->query("TRUNCATE TABLE `$records`.`m6_document_tracking`");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

// ---------------------------------------------------------------- users
$unusable = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT); // nobody can log in with a password
$staff = [
    ['demo', DEMO_EMAIL, 'Demo Administrator', 'admin'],
    ['acruz', 'andrea.cruz@example.com', 'Andrea Cruz', 'research'],
    ['mvillanueva', 'marco.villanueva@example.com', 'Marco Villanueva', 'researchstaff'],
    ['jdelrosario', 'joanna.delrosario@example.com', 'Joanna Del Rosario', 'researchstaff'],
    ['rmendoza', 'ramon.mendoza@example.com', 'Ramon Mendoza', 'records'],
    ['lbautista', 'liza.bautista@example.com', 'Liza Bautista', 'ordinance'],
    ['psantiago', 'paolo.santiago@example.com', 'Paolo Santiago', 'committee'],
];
$userIds = [];
foreach ($staff as $i => [$username, $email, $fullname, $type]) {
    $userIds[$username] = insert($conn, 'users', [
        'username' => $username, 'password' => $unusable, 'email' => $email, 'fullname' => $fullname,
        'date_created' => date('Y-m-d H:i:s', strtotime('-' . (200 - $i * 20) . ' days')),
        'avatar_url' => 'assets/img/default-avatar.png', 'user_type' => $type, 'archived' => 0,
    ]);
}
$reviewers = ['Andrea Cruz', 'Marco Villanueva', 'Joanna Del Rosario'];

// ---------------------------------------------------------------- measures
$councilors = ['Hon. Teresa Lacson', 'Hon. Daniel Ocampo', 'Hon. Patricia Garcia', 'Hon. Miguel Ramos',
               'Hon. Elena Navarro', 'Hon. Ricardo Salazar', 'Hon. Carmela Reyes', 'Hon. Victor Aquino'];
$topics = [
    'Health' => ['Barangay Health Station Modernization', 'Free Annual Medical Check-ups for Senior Citizens', 'Mental Health Awareness and Support Program', 'Mobile Vaccination Drive for Remote Barangays'],
    'Education' => ['Municipal Scholarship Program for Deserving Students', 'Public Library Digitization Project', 'Free School Supplies for Day Care Learners', 'Alternative Learning System Support Fund'],
    'Environment' => ['Plastic Reduction in Public Markets', 'Riverbank Rehabilitation and Tree Planting', 'Segregation-at-Source Solid Waste Enforcement', 'Urban Green Spaces Development'],
    'Infrastructure' => ['Farm-to-Market Road Rehabilitation', 'Covered Court Construction in Barangay San Isidro', 'Drainage Improvement along Rizal Avenue', 'Streetlight Installation Program'],
    'Public Safety' => ['Barangay Disaster Risk Reduction Equipment Fund', 'CCTV Installation in Major Intersections', 'Fire Safety Inspection of Commercial Buildings', 'Community Emergency Response Training'],
    'Transportation' => ['Tricycle Fare Rationalization', 'Designated Loading and Unloading Zones', 'Pedestrian Crossing Safety Improvements', 'Public Utility Vehicle Terminal Regulation'],
    'Economic Development' => ['Support for Micro, Small and Medium Enterprises', 'Night Market Operations Guidelines', 'Local Tourism Promotion Program', 'Cooperative Development Incentives'],
    'Social Services' => ['Persons with Disability Assistance Program', 'Solo Parent Livelihood Support', 'Child-Friendly Municipality Initiatives', 'Emergency Shelter Assistance for Fire Victims'],
    'Finance' => ['Revised Schedule of Market Stall Fees', 'Real Property Tax Amnesty Program', 'Supplemental Budget for Calamity Response', 'Business Permit Fee Simplification'],
    'Agriculture' => ['Free Rice Seed Distribution for Small Farmers', 'Fisherfolk Registration and Support Program', 'Organic Farming Incentives', 'Livestock Vaccination Program'],
];
$statusPlan = array_merge(
    array_fill(0, 8, 'Draft'),
    array_fill(0, 5, 'In Review for Docketing'),
    array_fill(0, 3, '1st Reading'),
    array_fill(0, 3, '2nd Reading'),
    array_fill(0, 3, '3rd Reading'),
    array_fill(0, 10, 'Enacted')
);

$n = 0;
$measures = [];
foreach ($statusPlan as $status) {
    $n++;
    $category = array_keys($topics)[($n - 1) % count($topics)];
    $subject = $topics[$category][intdiv($n - 1, count($topics)) % 4];
    $type = ($n % 3 === 0) ? 'Resolution' : 'Ordinance';
    $title = $type === 'Ordinance'
        ? "An Ordinance Establishing the $subject"
        : "A Resolution Supporting the $subject";
    $authors = [pick($councilors)];
    if ($n % 2) {
        $authors[] = pick(array_values(array_diff($councilors, $authors)));
    }
    // Drafts land in the current month so the dashboard calendar has entries
    $created = $status === 'Draft'
        ? date('Y-m-') . str_pad((string) min(28, 1 + $n * 3), 2, '0', STR_PAD_LEFT)
        : date('Y-m-d', strtotime('-' . (20 + $n * 9) . ' days'));
    $content = "Section 1. Title. This " . strtolower($type) . " shall be known as the \"$subject " . ($type === 'Ordinance' ? 'Ordinance' : 'Resolution') . "\".\n\n"
        . "Section 2. Declaration of Policy. It is the policy of the Municipality to promote the general welfare of its constituents in the area of " . strtolower($category) . ", "
        . "consistent with the Local Government Code of 1991.\n\n"
        . "Section 3. Coverage. This measure shall apply to all barangays within the territorial jurisdiction of the Municipality.\n\n"
        . "Section 4. Implementing Office. The Municipal " . ($category === 'Finance' ? 'Treasurer' : 'Administrator') . " shall issue the implementing guidelines within sixty (60) days from approval.\n\n"
        . "Section 5. Appropriation. Such amount as may be necessary shall be included in the annual budget of the Municipality.\n\n"
        . "Section 6. Effectivity. This measure shall take effect fifteen (15) days after its publication.";

    $isPast = $status !== 'Draft';
    $mcCode = sprintf('MC-%s-%03d', date('Y', strtotime($created)), $n);
    $m1 = insert($conn, 'm1_measurecreation', [
        'm1_MC_code' => $mcCode, 'id' => $userIds['lbautista'], 'fullname' => 'Liza Bautista', 'district' => 'District ' . (1 + $n % 2),
        'date_created' => $created, 'measure_type' => $type, 'measure_title' => $title, 'measure_content' => $content,
        'introducers' => implode(', ', $authors), 'measure_status' => $status, 'category' => $category,
        'checking_remarks_pending' => $status === 'Draft' ? 'For similarity checking' : null,
        'checking_remarks_approved' => $isPast ? 'No duplicate or conflicting measure found' : null,
        'docket_no' => $isPast ? sprintf('DKT-%s-%03d', date('Y', strtotime($created)), $n) : null,
        'sp_no' => $status === 'Enacted' ? sprintf('%s-%02d', $type === 'Ordinance' ? 'ORD' : 'RES', $n) : null,
    ]);
    $m9 = insert($conn, 'm9_similaritychecking', [
        'm9_SC_Code' => sprintf('SC-%s-%03d', date('Y', strtotime($created)), $n), 'm1_MC_ID' => $m1, 'm1_MC_code' => $mcCode,
        'date_created' => $created, 'measure_type' => $type, 'measure_title' => $title, 'measure_content' => $content,
        'introducers' => implode(', ', $authors), 'measure_status' => $status, 'category' => $category,
        'checking_remarks_pending' => $status === 'Draft' ? 'For similarity checking' : null,
        'checking_remarks_approved' => $isPast ? 'No duplicate or conflicting measure found' : null,
        'checking_notes' => $isPast ? 'Checked against existing ordinances and resolutions of the last ten years.' : null,
        'checked_by' => $isPast ? pick($reviewers) : null,
        'datetime_submitted' => $isPast ? date('Y-m-d H:i:s', strtotime($created . ' +3 days 10:30')) : null,
        'docket_no' => $isPast ? sprintf('DKT-%s-%03d', date('Y', strtotime($created)), $n) : null,
        'sp_no' => $status === 'Enacted' ? sprintf('%s-%02d', $type === 'Ordinance' ? 'ORD' : 'RES', $n) : null,
        'year_cc' => $status === 'Enacted' ? date('Y', strtotime($created)) : null,
        'sync_status' => $isPast ? 'synced' : 'pending',
    ]);
    $measures[] = compact('m9', 'm1', 'title', 'type', 'status', 'created', 'content', 'authors', 'n');

    // Review trail for measures that went through the research section
    if ($status !== 'Draft') {
        foreach (['1st_review' => 'Initial legal and format review completed.', '2nd_review' => 'Cross-checked with related national laws; no conflict found.', 'final_review' => 'Recommended for docketing.'] as $stage => $note) {
            insert($conn, 'm9_measure_reviews', [
                'm9_SC_ID' => $m9, 'review_stage' => $stage, 'review_notes' => $note, 'reviewed_by' => pick($reviewers),
                'reviewed_at' => date('Y-m-d H:i:s', strtotime($created . ' +1 day')),
            ]);
        }
    }
}

// ---------------------------------------------------------------- records (docketing + tracking)
$docketed = array_values(array_filter($measures, function ($m) { return $m['status'] !== 'Draft'; }));
$offices = ['Committee on Health', 'Committee on Education', 'Committee on Environment', 'Committee on Public Works', 'Committee on Finance'];
foreach (array_slice($docketed, 0, 12) as $i => $m) {
    $md = insert($conn, "$records`.`m6_measuredocketing", [
        'm6_MD_Code' => sprintf('MD-%03d', $i + 1), 'm8_SC_ID' => $i + 1, 'm8_SC_Code' => 100 + $i, 'm9_SC_ID' => $m['m9'],
        'm9_SC_Code' => sprintf('SC-%03d', $m['n']), 'date_created' => $m['created'], 'measure_type' => strtolower($m['type']),
        'measure_title' => $m['title'], 'measure_content' => $m['content'], 'introducers' => implode(', ', $m['authors']),
        'measure_status' => $i % 3 === 0 ? 'pending' : 'docketed', 'checking_remarks' => 'Complete requirements',
        'checking_notes' => 'Forwarded by the Research Section', 'checked_by' => 'Ramon Mendoza',
        'datetime_submitted' => date('Y-m-d H:i:s', strtotime($m['created'] . ' +5 days 09:00')),
        'docket_no' => sprintf('DKT-%s-%03d', date('Y', strtotime($m['created'])), $m['n']),
        'MFL_Name' => $offices[$i % count($offices)], 'MFL_Feedback' => $i % 2 ? 'For committee hearing' : 'Endorsed for first reading',
    ]);
    insert($conn, "$records`.`m6_document_tracking", [
        'measure_id' => $md, 'remarks' => pick(['Received by the Secretariat', 'Forwarded to committee', 'Returned for minor revisions', 'Endorsed to the Presiding Officer']),
        'date_sent' => date('Y-m-d H:i:s', strtotime($m['created'] . ' +6 days 14:00')),
        'date_received' => $i % 4 === 0 ? null : date('Y-m-d H:i:s', strtotime($m['created'] . ' +7 days 08:30')),
    ]);
    insert($conn, 'm6_measuredocketing_fromresearch', [
        'm6_MD_Code' => sprintf('MD-%03d', $i + 1), 'm9_SC_ID' => $m['m9'], 'm9_SC_Code' => sprintf('SC-%03d', $m['n']), 'm1_MC_ID' => $m['m1'],
        'date_created' => $m['created'], 'measure_type' => $m['type'], 'measure_title' => $m['title'], 'measure_content' => $m['content'],
        'introducers' => implode(', ', $m['authors']), 'measure_status' => $m['status'], 'checking_remarks' => 'Approved',
        'checked_by' => pick($reviewers), 'datetime_submitted' => date('Y-m-d H:i:s', strtotime($m['created'] . ' +3 days 10:30')),
    ]);
}

// ---------------------------------------------------------------- document requests
$requesters = [['Carlo Fernandez', 'carlo.fernandez@example.com'], ['Bea Tolentino', 'bea.tolentino@example.com'],
               ['Nico Pascual', 'nico.pascual@example.com'], ['Grace Domingo', 'grace.domingo@example.com']];
$reasons = ['For a barangay council presentation', 'Thesis research on local legislation', 'Business permit requirements', 'Reference for a community project proposal'];
foreach (['Pending', 'Pending', 'Processing', 'Approved', 'Completed', 'Completed', 'Rejected', 'Pending'] as $i => $reqStatus) {
    [$name, $email] = $requesters[$i % count($requesters)];
    insert($conn, 'document_requests', [
        'user_id' => 1000 + $i, 'username' => $name, 'email' => $email,
        'measure_title' => $docketed[$i % count($docketed)]['title'], 'request_reason' => $reasons[$i % count($reasons)],
        'status' => $reqStatus, 'request_date' => date('Y-m-d H:i:s', strtotime('-' . (2 + $i * 3) . ' days')),
        'admin_notes' => $reqStatus === 'Rejected' ? 'Requested measure is still under deliberation.' : null,
    ]);
}

// ---------------------------------------------------------------- research inbox (payloads from other systems)
foreach (array_slice($measures, 0, 5) as $i => $m) {
    insert($conn, 'research_inbox', [
        'payload_json' => json_encode(['source' => pick(['ordinance', 'agenda', 'committee']), 'docket_no' => sprintf('DKT-%03d', $m['n']),
                                       'measure_title' => $m['title'], 'note' => 'Please check for similar existing measures.']),
        'received_at' => date('Y-m-d H:i:s', strtotime('-' . ($i + 1) . ' days 11:00')), 'remote_addr' => '10.0.0.' . (10 + $i),
    ]);
}

// ---------------------------------------------------------------- activity log
$activities = [
    ['acruz', 'review', 'Completed 1st Review for measure ID: %d'],
    ['mvillanueva', 'submit', "Measure '%s' submitted for review"],
    ['jdelrosario', 'review', 'Completed Final Review for measure ID: %d'],
    ['acruz', 'login', 'User logged in successfully'],
    ['rmendoza', 'update', 'Docketed measure %s'],
    ['mvillanueva', 'login', 'User logged in successfully'],
];
for ($i = 0; $i < 18; $i++) {
    [$who, $action, $template] = $activities[$i % count($activities)];
    $m = $measures[$i % count($measures)];
    insert($conn, 'audit_logs', [
        'user_id' => $userIds[$who], 'username' => $who, 'action_type' => $action,
        'action_description' => sprintf($template, strpos($template, '%d') !== false ? $m['m9'] : $m['title']),
        'ip_address' => '127.0.0.1', 'user_agent' => 'Demo data',
        'created_at' => date('Y-m-d H:i:s', strtotime('-' . ($i * 5 + 1) . ' hours')),
    ]);
}

printf("Demo data loaded: %d users, %d measures, %d docketed, 8 document requests, 5 inbox items, 18 activities.\n",
    count($staff), count($measures), min(12, count($docketed)));
