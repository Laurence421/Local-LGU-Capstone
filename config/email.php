<?php
// Email configuration — values come from .env (see .env.example)
require_once __DIR__ . '/env.php';

defined('MAIL_HOST') || define('MAIL_HOST', env('MAIL_HOST', 'smtp.gmail.com'));
defined('MAIL_USERNAME') || define('MAIL_USERNAME', env('MAIL_USERNAME', ''));
defined('MAIL_PASSWORD') || define('MAIL_PASSWORD', env('MAIL_PASSWORD', ''));
defined('MAIL_PORT') || define('MAIL_PORT', (int) env('MAIL_PORT', 587));
defined('MAIL_FROM_EMAIL') || define('MAIL_FROM_EMAIL', env('MAIL_FROM_EMAIL', env('MAIL_USERNAME', '')));
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'LGU System'));
