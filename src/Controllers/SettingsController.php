<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SystemSettingsService;
use Psr\Http\Message\{ResponseInterface as Response, ServerRequestInterface as Request};

final class SettingsController
{
    public function __construct(
        private SystemSettingsService $settings,
        private array $llmCfg,
    ) {}

    // ── GET /api/v1/settings/llm ────────────────────────────────────

    public function getLlm(Request $request, Response $response): Response
    {
        try {
            $config = $this->settings->getLlmModelConfig();
            $models = $this->fetchSidecarModels();

            return $this->json($response, [
                'config'           => $config,
                'available_models' => $models,
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, [
                'error' => 'Failed to load LLM settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── POST /api/v1/settings/llm ───────────────────────────────────

    public function saveLlm(Request $request, Response $response): Response
    {
        try {
            $body   = (array) $request->getParsedBody();
            $config = [];

            if (array_key_exists('default_model', $body)) {
                $val = $body['default_model'];
                $config['default_model'] = ($val === '' || $val === null) ? null : (string) $val;
            }

            if (array_key_exists('step_models', $body) && is_array($body['step_models'])) {
                $allowed    = ['intent_detection', 'sql_generation', 'chart_suggestion'];
                $stepModels = [];
                foreach ($allowed as $step) {
                    $val = $body['step_models'][$step] ?? null;
                    $stepModels[$step] = ($val === '' || $val === null) ? null : (string) $val;
                }
                $config['step_models'] = $stepModels;
            }

            $this->settings->setLlmModelConfig($config);

            return $this->json($response, ['status' => 'saved']);
        } catch (\Throwable $e) {
            return $this->json($response, [
                'error' => 'Failed to save LLM settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Proxy the sidecar /v1/models endpoint so the frontend doesn't need
     * the sidecar URL or auth credentials.
     */
    private function fetchSidecarModels(): array
    {
        $baseUrl = rtrim($this->llmCfg['base_url'] ?? '', '/');
        $secret  = $this->llmCfg['api_secret'] ?? '';

        $opts = [
            'http' => [
                'timeout' => 5,
                'header'  => $secret ? "Authorization: Bearer {$secret}\r\n" : '',
            ],
        ];
        $ctx = stream_context_create($opts);
        $raw = @file_get_contents("{$baseUrl}/v1/models", false, $ctx);

        if ($raw === false) {
            return ['aliases' => [], 'format' => 'provider:model-name'];
        }

        return json_decode($raw, true) ?? ['aliases' => [], 'format' => 'provider:model-name'];
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
