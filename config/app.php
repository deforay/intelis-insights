<?php

declare(strict_types=1);

// Helper: read env from $_ENV (phpdotenv) with getenv() fallback
$env = fn(string $key, string $default = '') => $_ENV[$key] ?? (getenv($key) ?: $default);

// Version: written at build/deploy time by bin/version.sh
$version = trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION'));

return [
    'version' => $version ?: 'dev',
    'env' => $env('APP_ENV', 'development'),
    'debug' => filter_var($env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN),
    'timezone' => $env('APP_TIMEZONE', 'UTC'),

    // LLM Sidecar (~/www/llm-sidecar)
    'llm' => [
        'base_url' => $env('LLM_SIDECAR_URL', 'http://127.0.0.1:3100'),
        'api_secret' => $env('LLM_SIDECAR_SECRET'),
        'default_model' => $env('LLM_DEFAULT_MODEL', 'sonnet'),
        'timeout' => 120,
    ],

    // RAG Sidecar
    'rag' => [
        'base_url' => $env('RAG_BASE_URL', 'http://127.0.0.1:8089'),
        'enabled' => filter_var($env('RAG_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],

    // Schema & DB
    'schema_path' => __DIR__ . '/../var/schema.json',
    'db_name' => $env('QUERY_DB_NAME', 'vlsm'),
    'default_limit' => 200,

    // RAG base URL (flat key used by rag-refresh.sh)
    'rag_base_url' => $env('RAG_BASE_URL', 'http://127.0.0.1:8089'),
    'rag_enabled' => filter_var($env('RAG_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),

    // Cache
    'cache' => [
        'driver' => $env('CACHE_DRIVER', 'file'),
        'namespace' => 'insights',
        'path' => __DIR__ . '/../var/cache',
        'ttl' => 300,
        'redis_dsn' => $env('REDIS_DSN', 'redis://127.0.0.1:6379'),
        'buster' => $env('CACHE_BUSTER', (string) @filemtime(__DIR__ . '/../corpus/snippets.jsonl')),
    ],

    // Privacy
    'suppression_threshold' => 5,
];
