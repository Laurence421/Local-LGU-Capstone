<?php
// App database connection as $lgu2_conn. Settings come from .env via bootstrap.php.
require_once __DIR__ . '/../../bootstrap.php';

$lgu2_conn = require_db(db_connect());
