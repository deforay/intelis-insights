<?php
// public/index.php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use App\Llm\LlmRouter;
use App\Services\ChartService;
use App\Services\QueryService;
use App\Services\DatabaseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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
$router          = new LlmRouter($appCfg);
// Pass null for ConversationContextService (5th param) and LlmRouter as 6th param
$queryService    = new QueryService($appCfg, $businessRules, $fieldGuide, $schema, null, $router);
$databaseService = new DatabaseService($dbCfg);
$chartService    = new ChartService($router);

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
$app->post('/ask', function ($request, $response) use ($queryService, $databaseService, $chartService) {
    $startTime = microtime(true);
    try {
        $body  = (array)$request->getParsedBody();
        $query = trim((string)($body['q'] ?? ''));

        // Check for clear context request
        if (isset($body['clear_context']) && $body['clear_context']) {
            $queryService->clearConversationHistory();
            $response->getBody()->write(json_encode([
                'message' => 'Conversation context cleared',
                'context_reset' => true
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        if ($query === '') {
            $response->getBody()->write(json_encode(['error' => 'Missing "q" parameter']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // per-request override
        $provider = isset($body['provider']) ? (string)$body['provider'] : null;
        $model    = isset($body['model'])    ? (string)$body['model']    : null;
        $queryService->overrideLlm($provider, $model);

        // per-step override map: {"intent": {...}, "sql": {...}, "chart": {...}}
        if (!empty($body['provider_map']) && is_array($body['provider_map'])) {
            $queryService->overrideLlmMap($body['provider_map']);
        }

        $queryResult = $queryService->processQuery($query);
        $dbResult    = $databaseService->executeQuery($queryResult['sql']);

        // Analyze data for chart suggestions (only if reasonable dataset)
        $chartSuggestions = null;
        if ($dbResult['count'] > 0 && $dbResult['count'] <= 1000) {
            try {
                $chartSuggestions = $chartService->analyzeDataForCharts(
                    $dbResult,
                    $queryResult['intent'],
                    $query
                );
            } catch (\Throwable $chartError) {
                // Chart analysis failure shouldn't break the main query
                error_log("Chart analysis failed: " . $chartError->getMessage());
            }
        }

        $llmIdent  = $queryService->getLlmIdentity();
        $totalTime = microtime(true) - $startTime;

        $response_data = [
            'sql'   => $queryResult['sql'],
            'verification' => $queryResult['verification'] ?? null,
            'concerns' => $queryResult['concerns'] ?? [],
            'citations' => $queryResult['citations'] ?? [],
            'retrieved_context_ids' => $queryResult['retrieved_context_ids'] ?? [],
            'context' => $queryResult['context'] ?? [],
            'count' => $dbResult['count'],
            'rows'  => $dbResult['rows'],
            'chart_suggestions' => $chartSuggestions,
            'timing' => [
                'llm' => [
                    'intent' => $llmIdent['intent'],
                    'sql'    => $llmIdent['sql'],
                    'chart'  => $llmIdent['chart'],
                ],
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
                ],
                'conversation_context' => $queryResult['conversation_context'] ?? [],
                'conversation_history_count' => count($queryService->getConversationHistory()),
                'conversation_history' => $queryService->getConversationHistory()
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

$app->post('/chart-data', function ($request, $response) use ($chartService) {
    try {
        $body = (array)$request->getParsedBody();
        $rows = $body['rows'] ?? [];
        $chartConfig = $body['chart_config'] ?? [];

        if (empty($rows) || empty($chartConfig)) {
            throw new \InvalidArgumentException('Missing rows or chart_config');
        }

        $formattedData = $chartService->formatDataForECharts($rows, $chartConfig);

        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => $formattedData
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (\Throwable $e) {
        $response->getBody()->write(json_encode([
            'error' => 'Chart data formatting failed',
            'detail' => $e->getMessage()
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});

// Run the application
$app->run();
