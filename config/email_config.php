<?php
// Email Configuration for LGU Research System — values come from .env (see .env.example)
require_once __DIR__ . '/env.php';

return [
    'smtp' => [
        'host' => env('MAIL_HOST', 'smtp.gmail.com'),
        'port' => (int) env('MAIL_PORT', 587),
        'encryption' => 'tls', // or 'ssl'
        'auth' => true,
        'username' => env('MAIL_USERNAME', ''),
        'password' => env('MAIL_PASSWORD', ''),
    ],
    'from' => [
        'email' => env('MAIL_FROM_EMAIL', 'noreply@lgu-archive.local'),
        'name' => env('MAIL_FROM_NAME', 'LGU Archive System'),
    ],
    'local_mode' => false, // Set to false when SMTP is configured
];
