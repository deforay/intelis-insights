<?php
// public/index.php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\QueryService;
use App\Services\DatabaseService;

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Boot Slim
$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Load configurations
$appCfg = require __DIR__ . '/../config/app.php';
$dbCfg = require __DIR__ . '/../config/db.php';
$businessRules = require __DIR__ . '/../config/business-rules.php';
$fieldGuide = require __DIR__ . '/../config/field-guide.php';

// Load schema
$schemaPath = $appCfg['schema_path'];
$schema = is_file($schemaPath) ? json_decode(file_get_contents($schemaPath), true) : [];

// Initialize services
$queryService = new QueryService($appCfg, $businessRules, $fieldGuide, $schema);
$databaseService = new DatabaseService($dbCfg);

// Health check routes
$app->get('/', function ($request, $response) {
    $data = ['status' => 'ok', 'service' => 'AI SQL Generator'];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/status', function ($request, $response) use ($databaseService) {
    $dbConnected = $databaseService->testConnection();
    $data = [
        'status' => $dbConnected ? 'ok' : 'error',
        'database' => $dbConnected ? 'connected' : 'disconnected',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/chat', function (Request $request, Response $response) {
    ob_start();
    require __DIR__ . '/../src/Views/chat.php'; // executes PHP
    $html = ob_get_clean();

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
});

$app->get('/version', function ($request, $response) {
    $versionFile = dirname(__DIR__) . '/VERSION';
    $version = is_file($versionFile) ? trim(file_get_contents($versionFile)) : 'dev';
    $data = ['version' => $version];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// Main API endpoint
$app->post('/ask', function ($request, $response) use ($queryService, $databaseService) {
    $startTime = microtime(true);
    try {
        $body  = (array)$request->getParsedBody();
        $query = trim((string)($body['q'] ?? ''));
        $appCfg = require __DIR__ . '/../config/app.php';

        if ($query === '') {
            $response->getBody()->write(json_encode(['error' => 'Missing "q" parameter']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // per-request override
        $provider = isset($body['provider']) ? (string)$body['provider'] : null;
        $model    = isset($body['model'])    ? (string)$body['model']    : null;
        $queryService->overrideLlm($provider, $model);

        $queryResult = $queryService->processQuery($query);
        $dbResult    = $databaseService->executeQuery($queryResult['sql']);

        $llmIdent = $queryService->getLlmIdentity();
        $totalTime = microtime(true) - $startTime;

        $response_data = [
            'sql'   => $queryResult['sql'],
            'count' => $dbResult['count'],
            'rows'  => $dbResult['rows'],
            'timing' => [
                'provider' => $llmIdent['provider'] ?? 'unknown',
                'model_used' => $llmIdent['model'] ?? 'unknown',
                'total_ms' => round($totalTime * 1000),
                'query_processing_ms' => $queryResult['processing_time_ms'],
                'db_execution_ms' => $dbResult['execution_time_ms'],
                'tables_selected_for_context' => $queryResult['tables_selected'],
                'tables_actually_used' => $queryResult['tables_used'],
            ],
            'debug' => [
                'raw_sql' => $queryResult['raw_sql'],
                'detected_intent' => $queryResult['intent'],
                'context_summary' => [
                    'schema_tables' => $queryResult['tables_selected'],
                    'context_sections' => ($queryResult['context'])
                ]
            ]
        ];

        $response->getBody()->write(json_encode($response_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (\Throwable $e) {
        $errorData = [
            'error' => 'Query processing failed',
            'detail' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        if (isset($queryResult['raw_sql'])) {
            $errorData['raw_sql'] = $queryResult['raw_sql'];
        }
        $response->getBody()->write(json_encode($errorData));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});

// Run the application
$app->run();