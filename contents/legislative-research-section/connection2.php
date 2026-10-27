<?php
// App database connection as $conn for JSON endpoints. Settings come from .env via bootstrap.php.
require_once __DIR__ . '/../../bootstrap.php';

$conn = require_db(db_connect(), true);
