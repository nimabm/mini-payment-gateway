<?php

declare(strict_types=1);

/**
 * Deployment configuration, read once from the environment.
 *
 * Anything an operator should be able to change at runtime belongs in the
 * settings table and the admin panel, not here.
 */
return [
    'app' => [
        'env' => $_ENV['APP_ENV'] ?? 'production',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
        'url' => rtrim((string) ($_ENV['APP_URL'] ?? 'http://localhost:8080'), '/'),
        'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
        'key' => (string) ($_ENV['APP_KEY'] ?? ''),
    ],

    'database' => [
        'path' => (string) ($_ENV['DB_PATH'] ?? __DIR__ . '/../var/gateway.sqlite'),
        'migrations' => __DIR__ . '/../database/migrations',
    ],

    'api' => [
        'signature_tolerance' => (int) ($_ENV['API_SIGNATURE_TOLERANCE'] ?? 300),
        'rate_limit' => (int) ($_ENV['API_RATE_LIMIT'] ?? 120),
    ],

    'payment' => [
        'ttl_minutes' => (int) ($_ENV['PAYMENT_TTL_MINUTES'] ?? 30),
        'max_failover' => (int) ($_ENV['CHECKOUT_MAX_FAILOVER'] ?? 3),
    ],

    'webhook' => [
        'retry_schedule' => (string) ($_ENV['WEBHOOK_RETRY_SCHEDULE'] ?? '1,5,30,120,360,1440'),
    ],

    'logging' => [
        'level' => (string) ($_ENV['LOG_LEVEL'] ?? 'info'),
        'path' => (string) ($_ENV['LOG_PATH'] ?? 'php://stderr'),
    ],

    'paths' => [
        'templates' => __DIR__ . '/../templates',
        'translations' => __DIR__ . '/../resources/lang',
        'cache' => __DIR__ . '/../var/cache',
    ],
];
