<?php
declare(strict_types=1);

/**
 * Optional: SMTP credentials for outgoing email.
 *
 * These are read by `email.php` and take precedence over any database values.
 * Keep this file private (do not commit secrets to git).
 */
if (!defined('ILDRA_EMAIL_SMTP_USERNAME')) {
    define('ILDRA_EMAIL_SMTP_USERNAME', '');
}
if (!defined('ILDRA_EMAIL_SMTP_PASSWORD')) {
    define('ILDRA_EMAIL_SMTP_PASSWORD', '');
}

$serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
$httpHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocal = $serverName === 'localhost'
    || $serverName === '127.0.0.1'
    || $httpHost === 'localhost'
    || $httpHost === '127.0.0.1'
    || $httpHost === 'localhost:8888'
    || $httpHost === '127.0.0.1:8888'
    || PHP_SAPI === 'cli';

$dbConfig = $isLocal
    ? [
        'host' => '127.0.0.1',
        'port' => '8889',
        'name' => '',
        'user' => '',
        'pass' => '',
        'charset' => 'utf8mb4',
    ]
    : [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => '',
        'user' => '',
        'pass' => '',
        'charset' => 'utf8mb4',
    ];

return [
    'db' => $dbConfig,
    'stripe' => [
        'publishable_key' => '',
        'secret_key' => '',
        'webhook_secret' => '',
        'currency' => 'gbp',
    ],
];
