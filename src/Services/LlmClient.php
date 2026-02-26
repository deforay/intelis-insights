<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Thin HTTP wrapper for the llm-sidecar service (~/www/llm-sidecar).
 *
 * Endpoints used:
 *   POST /v1/chat        – free-form text completion
 *   POST /v1/structured  – JSON schema-validated output
 *   POST /v1/embeddings  – text embeddings
 */
final class LlmClient
{
    private Client $http;
    private string $defaultModel;

    public function __construct(array $cfg)
    {
        $this->defaultModel = $cfg['default_model'] ?? 'sonnet';
        $this->http = new Client([
            'base_uri' => rtrim($cfg['base_url'], '/') . '/',
            'timeout' => $cfg['timeout'] ?? 30,
            'connect_timeout' => 5,
            'headers' => array_filter([
                'Authorization' => $cfg['api_secret'] ? 'Bearer ' . $cfg['api_secret'] : null,
                'Content-Type' => 'application/json',
            ]),
        ]);
    }

    /**
     * Free-form chat completion — returns the content string.
     */
    public function chat(
        string $system,
        string $userPrompt,
        ?string $model = null,
        float $temperature = 0.7,
        int $maxTokens = 2000,
    ): string {
        $resp = $this->http->post('v1/chat', [
            'json' => [
                'model' => $model ?? $this->defaultModel,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ],
        ]);

        $data = json_decode((string) $resp->getBody(), true);
        $content = trim($data['content'] ?? '');
        if ($content === '') {
            throw new RuntimeException('Empty LLM response');
        }
        return $content;
    }

    /**
     * Structured output — returns validated JSON as associative array.
     * The LLM response is guaranteed to match the provided JSON schema.
     */
    public function structured(
        string $system,
        string $userPrompt,
        array $schema,
        string $schemaName = 'output',
        ?string $model = null,
        float $temperature = 0.0,
        int $maxTokens = 2000,
    ): array {
        $resp = $this->http->post('v1/structured', [
            'json' => [
                'model' => $model ?? $this->defaultModel,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'schema' => $schema,
                'schema_name' => $schemaName,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ],
        ]);

        $data = json_decode((string) $resp->getBody(), true);
        return $data['data'] ?? [];
    }

    /**
     * Structured output with fallback to chat + JSON extraction.
     * Tries /v1/structured first; if it fails (e.g. Ollama doesn't support
     * tool calling), falls back to /v1/chat with a JSON-only system prompt.
     */
    public function structuredWithFallback(
        string $system,
        string $userPrompt,
        array $schema,
        string $schemaName = 'output',
        ?string $model = null,
        float $temperature = 0.0,
        int $maxTokens = 2000,
    ): array {
        try {
            return $this->structured($system, $userPrompt, $schema, $schemaName, $model, $temperature, $maxTokens);
        } catch (\Throwable $e) {
            // Fallback: use regular chat with explicit JSON instruction
            $jsonSystem = $system . "\n\nIMPORTANT: You MUST respond with ONLY valid JSON matching this schema. No markdown, no explanation, just JSON.\n\nSchema:\n" . json_encode($schema, JSON_PRETTY_PRINT);

            $raw = $this->chat($jsonSystem, $userPrompt, $model, $temperature, $maxTokens);

            // Extract JSON from the response (handle markdown code blocks)
            if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $raw, $m)) {
                $raw = $m[1];
            }
            $raw = trim($raw);

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('LLM fallback did not return valid JSON: ' . substr($raw, 0, 200));
            }
            return $decoded;
        }
    }

    /**
     * Text embeddings — returns array of float vectors.
     */
    public function embeddings(array $texts, ?string $model = null): array
    {
        $resp = $this->http->post('v1/embeddings', [
            'json' => [
                'model' => $model ?? 'embed-small',
                'input' => $texts,
            ],
        ]);

        $data = json_decode((string) $resp->getBody(), true);
        return $data['embeddings'] ?? [];
    }
}
