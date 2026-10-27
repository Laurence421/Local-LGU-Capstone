<?php
/**
 * Apply migrations/*.sql in order, once each. Command line only.
 *
 *   php tools/migrate.php             apply pending migrations
 *   php tools/migrate.php --status    list applied / pending
 *   php tools/migrate.php --baseline  record 0001_init.sql as applied without running it
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

$conn = db_connect();
if ($conn->connect_errno) {
    fwrite(STDERR, "Cannot connect to database: {$conn->connect_error}\n");
    exit(1);
}

$conn->query("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = [];
$result = $conn->query("SELECT filename FROM schema_migrations");
while ($row = $result->fetch_assoc()) {
    $applied[$row['filename']] = true;
}

$files = glob(__DIR__ . '/../migrations/*.sql');
sort($files);

$mode = $argv[1] ?? '';

if ($mode === '--status') {
    foreach ($files as $file) {
        $name = basename($file);
        echo (isset($applied[$name]) ? '[applied] ' : '[pending] ') . $name . "\n";
    }
    exit(0);
}

if ($mode === '--baseline') {
    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (filename) VALUES ('0001_init.sql')");
    $stmt->execute();
    echo "Recorded 0001_init.sql as applied.\n";
    exit(0);
}

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }

    echo "Applying {$name} ... ";
    if (!$conn->multi_query(file_get_contents($file))) {
        echo "FAILED\n{$conn->error}\n";
        exit(1);
    }
    // Drain every statement's result; stop at the first error
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    if ($conn->errno) {
        echo "FAILED\n{$conn->error}\n";
        exit(1);
    }

    $stmt = $conn->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->close();
    echo "ok\n";
    $ran++;
}

echo $ran ? "Done ({$ran} applied).\n" : "Nothing to apply.\n";
