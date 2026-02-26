<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Load .env if present
if (is_file(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Helper: read env from $_ENV (phpdotenv) with getenv() fallback
$env = fn(string $key, string $default = '') => $_ENV[$key] ?? (getenv($key) ?: $default);

// Load configuration
$appCfg = require __DIR__ . '/../config/app.php';
$dbCfg  = require __DIR__ . '/../config/db.php';

// Load domain configs
$businessRules = require __DIR__ . '/../config/business-rules.php';
$fieldGuide    = require __DIR__ . '/../config/field-guide.php';
$schema        = is_file($appCfg['schema_path'])
    ? json_decode(file_get_contents($appCfg['schema_path']), true) ?? []
    : [];

// Boot Eloquent ORM — default connection (intelis_insights) + query connection (vlsm)
App\Bootstrap\Database::boot(
    [
        'host'     => $env('DB_HOST', '127.0.0.1'),
        'port'     => $env('DB_PORT', '3306'),
        'database' => $env('DB_NAME', 'intelis_insights'),
        'username' => $env('DB_USER', 'root'),
        'password' => $env('DB_PASSWORD'),
    ],
    [
        'host'     => $env('DB_HOST', '127.0.0.1'),
        'port'     => $env('DB_PORT', '3306'),
        'database' => $env('QUERY_DB_NAME', 'vlsm'),
        'username' => $env('QUERY_DB_USER', $env('DB_USER', 'root')),
        'password' => $env('QUERY_DB_PASSWORD', $env('DB_PASSWORD')),
    ],
);

// Boot Slim
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware($appCfg['debug'], true, true);

// CORS middleware
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Request-Id')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

// ── Services ───────────────────────────────────────────────────────

// LLM Sidecar — all LLM calls go through this (~/www/llm-sidecar at :3100)
$llmClient = new App\Services\LlmClient($appCfg['llm']);

// RAG Sidecar — semantic search over indexed snippets (FastAPI + Qdrant at :8089)
$ragClient = new App\Services\RagClient($appCfg['rag']);

// DatabaseService for executing LLM-generated SQL against vlsm (raw PDO)
$queryDb = new App\Services\DatabaseService($dbCfg['query']);

// QueryService — the core LLM-generates-SQL pipeline
$queryService = new App\Services\QueryService(
    $appCfg, $businessRules, $fieldGuide, $schema,
    $llmClient, $ragClient,
);

$chartService = new App\Services\ChartService($llmClient);

// ── Controllers ────────────────────────────────────────────────────

$chatController   = new App\Controllers\ChatController($queryService, $queryDb, $chartService);
$reportController = new App\Controllers\ReportController();

// ── Landing page ───────────────────────────────────────────────────
$app->get('/', function ($request, $response) {
    ob_start();
    require __DIR__ . '/landing.php';
    $html = ob_get_clean();
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

// ── Health ─────────────────────────────────────────────────────────
$app->get('/health', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'status'  => 'ok',
        'service' => 'Intelis Insights API',
        'version' => '2.0.0',
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/status', function ($request, $response) use ($queryDb) {
    $dbOk = $queryDb->testConnection();
    $response->getBody()->write(json_encode([
        'status'    => $dbOk ? 'ok' : 'error',
        'database'  => $dbOk ? 'connected' : 'disconnected',
        'timestamp' => date('c'),
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// ── Chat API (LLM-generates-SQL pipeline) ──────────────────────────
$app->post('/api/v1/chat/ask', [$chatController, 'ask']);
$app->post('/api/v1/chat/clear-context', [$chatController, 'clearContext']);
$app->get('/api/v1/chat/history', [$chatController, 'history']);
$app->get('/api/v1/chat/history/{index}', [$chatController, 'historyItem']);
$app->post('/api/v1/chat/rewind/{index}', [$chatController, 'rewind']);

// ── Chart API ──────────────────────────────────────────────────────
$app->post('/api/v1/chart/suggest', [$chatController, 'suggestChart']);

// ── Reports API ────────────────────────────────────────────────────
$app->get('/api/v1/reports', [$reportController, 'list']);
$app->post('/api/v1/reports', [$reportController, 'create']);
$app->get('/api/v1/reports/{id}', [$reportController, 'get']);
$app->put('/api/v1/reports/{id}', [$reportController, 'update']);
$app->delete('/api/v1/reports/{id}', [$reportController, 'delete']);

$app->run();
