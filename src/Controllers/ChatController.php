<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Services\{QueryService, DatabaseService, ChartService};
use Psr\Http\Message\{ResponseInterface as Response, ServerRequestInterface as Request};
use Ramsey\Uuid\Uuid;

final class ChatController
{
    public function __construct(
        private QueryService $query,
        private DatabaseService $queryDb,
        private ChartService $chart,
    ) {}

    // ── POST /api/v1/chat/ask ───────────────────────────────────────

    /**
     * Full pipeline: intent → RAG → LLM SQL → execute → chart.
     *
     * Body: {"question": "...", "session_id": "..."}
     */
    public function ask(Request $request, Response $response): Response
    {
        $startTime = microtime(true);

        try {
            $body     = (array) $request->getParsedBody();
            $question = trim((string) ($body['question'] ?? ''));

            if ($question === '') {
                return $this->json($response, ['error' => 'Missing required field: question'], 400);
            }

            $sessionId = (string) ($body['session_id'] ?? '');

            // Ensure chat session exists (Eloquent)
            if ($sessionId !== '') {
                $session = ChatSession::find($sessionId);
                if (!$session) {
                    $session = ChatSession::create([
                        'id'    => $sessionId,
                        'title' => mb_substr($question, 0, 100),
                    ]);
                }
            }

            // ── Step 1: Process query through QueryService pipeline ──
            $queryResult = $this->query->processQuery($question);

            // ── Step 2: Execute the generated SQL against vlsm ───────
            $dbResult = $this->queryDb->execute($queryResult['sql']);

            // ── Step 3: Store in conversation history (in-memory) ─────
            $this->query->addToConversationHistory($question, $queryResult, $dbResult);

            // ── Step 4: Suggest chart ────────────────────────────────
            $chartSuggestion = $this->chart->suggest(
                $dbResult,
                $queryResult['intent'] ?? '',
                $question,
            );

            // ── Step 5: Persist to DB via Eloquent ───────────────────
            if ($sessionId !== '') {
                // User message
                ChatMessage::create([
                    'id'         => Uuid::uuid4()->toString(),
                    'session_id' => $sessionId,
                    'role'       => 'user',
                    'content'    => $question,
                ]);

                // Assistant message
                ChatMessage::create([
                    'id'               => Uuid::uuid4()->toString(),
                    'session_id'       => $sessionId,
                    'role'             => 'assistant',
                    'content'          => $queryResult['verification']['reasoning'] ?? '',
                    'query_result_json' => [
                        'sql'          => $queryResult['sql'],
                        'verification' => $queryResult['verification'],
                        'citations'    => $queryResult['citations'] ?? [],
                        'intent'       => $queryResult['intent'],
                        'tables_used'  => $queryResult['tables_used'],
                        'data'         => [
                            'columns' => $dbResult['columns'],
                            'count'   => $dbResult['count'],
                        ],
                    ],
                    'chart_json' => $chartSuggestion,
                ]);
            }

            $elapsedMs = round((microtime(true) - $startTime) * 1000, 1);

            return $this->json($response, [
                'sql'          => $queryResult['sql'],
                'verification' => $queryResult['verification'] ?? null,
                'citations'    => $queryResult['citations'] ?? [],
                'data'         => [
                    'columns' => $dbResult['columns'],
                    'rows'    => $dbResult['rows'],
                    'count'   => $dbResult['count'],
                ],
                'chart' => $chartSuggestion,
                'meta'  => [
                    'execution_time_ms'     => $elapsedMs,
                    'detected_intent'       => $queryResult['intent'],
                    'sql_execution_time_ms' => $dbResult['execution_time_ms'] ?? null,
                    'session_id'            => $sessionId,
                ],
                'debug' => [
                    'tables_used'          => $queryResult['tables_used'],
                    'conversation_context' => $queryResult['conversation_context'] ?? [],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, [
                'error'   => 'pipeline_error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ── POST /api/v1/chart/suggest ──────────────────────────────────

    public function suggestChart(Request $request, Response $response): Response
    {
        try {
            $body = (array) $request->getParsedBody();
            $data = (array) ($body['data'] ?? []);

            if (!isset($data['columns'], $data['rows'])) {
                return $this->json($response, ['error' => 'Missing required fields: data.columns, data.rows'], 400);
            }

            $result = [
                'columns' => (array) $data['columns'],
                'rows'    => (array) $data['rows'],
                'count'   => count((array) $data['rows']),
            ];

            $suggestion = $this->chart->suggest(
                $result,
                (string) ($body['intent'] ?? ''),
                (string) ($body['query'] ?? ''),
            );

            return $this->json($response, $suggestion);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Chart suggestion failed: ' . $e->getMessage()], 500);
        }
    }

    // ── POST /api/v1/chat/clear-context ─────────────────────────────

    public function clearContext(Request $request, Response $response): Response
    {
        $this->query->clearConversationHistory();
        return $this->json($response, ['context_reset' => true]);
    }

    // ── GET /api/v1/chat/history ────────────────────────────────────

    public function history(Request $request, Response $response): Response
    {
        $history = $this->query->getConversationHistory();
        return $this->json($response, ['turns' => $history, 'count' => count($history)]);
    }

    // ── GET /api/v1/chat/history/{index} ────────────────────────────

    public function historyItem(Request $request, Response $response): Response
    {
        $index = (int) $request->getAttribute('index');
        $item  = $this->query->getConversationHistoryItem($index);

        if ($item === null) {
            return $this->json($response, ['error' => 'History item not found'], 404);
        }

        return $this->json($response, $item);
    }

    // ── POST /api/v1/chat/rewind/{index} ────────────────────────────

    public function rewind(Request $request, Response $response): Response
    {
        $index = (int) $request->getAttribute('index');
        $this->query->rewindConversation($index);

        $history = $this->query->getConversationHistory();
        return $this->json($response, [
            'rewound_to' => $index,
            'turns'      => $history,
            'count'      => count($history),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
