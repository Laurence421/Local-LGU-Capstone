<?php
// Database configuration — values come from .env (see .env.example)
require_once __DIR__ . '/env.php';

defined('DB_HOST') || define('DB_HOST', env('DB_HOST', 'localhost'));
defined('DB_USER') || define('DB_USER', env('DB_USER', 'root'));
defined('DB_PASS') || define('DB_PASS', env('DB_PASS', ''));
defined('DB_NAME') || define('DB_NAME', env('DB_NAME', 'lgu2local'));
