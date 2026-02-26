<?php

declare(strict_types=1);

$env = fn(string $key, string $default = '') => $_ENV[$key] ?? (getenv($key) ?: $default);

$host = $env('DB_HOST', '127.0.0.1');
$port = $env('DB_PORT', '3306');

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

return [
    // App DB — intelis_insights (reports, app data)
    'app' => [
        'dsn' => "mysql:host={$host};port={$port};dbname=" . $env('DB_NAME', 'intelis_insights') . ";charset=utf8mb4",
        'user' => $env('DB_USER', 'root'),
        'password' => $env('DB_PASSWORD', 'zaq12345'),
        'options' => $options,
    ],
    // Query DB — vlsm (where LLM-generated SQL executes)
    'query' => [
        'dsn' => "mysql:host={$host};port={$port};dbname=" . $env('QUERY_DB_NAME', 'vlsm') . ";charset=utf8mb4",
        'user' => $env('QUERY_DB_USER', $env('DB_USER', 'root')),
        'password' => $env('QUERY_DB_PASSWORD', $env('DB_PASSWORD', 'zaq12345')),
        'options' => $options,
    ],
];
