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

    // ── GET /api/v1/settings/api-keys ────────────────────────────────

    public function getApiKeys(Request $request, Response $response): Response
    {
        try {
            $keys           = $this->settings->getApiKeys();
            $providerStatus = $this->fetchSidecarProviderStatus();

            // Opportunistically sync stored keys to sidecar (handles sidecar restarts)
            $rawKeys = $this->settings->getRawApiKeys();
            if (!empty($rawKeys)) {
                try { $this->pushKeysToSidecar($rawKeys); } catch (\Throwable) {}
            }

            return $this->json($response, [
                'keys'      => $keys,
                'providers' => $providerStatus,
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, [
                'error' => 'Failed to load API keys: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── POST /api/v1/settings/api-keys ───────────────────────────────

    public function saveApiKeys(Request $request, Response $response): Response
    {
        try {
            $body = (array) $request->getParsedBody();
            $keys = [];

            $allowed = ['anthropic', 'openai', 'google', 'groq', 'deepseek'];
            foreach ($allowed as $provider) {
                if (array_key_exists($provider, $body)) {
                    $keys[$provider] = (string) $body[$provider];
                }
            }

            if (empty($keys)) {
                return $this->json($response, [
                    'error' => 'No valid provider keys provided',
                ], 400);
            }

            // Store in database
            $this->settings->setApiKeys($keys);

            // Push ALL stored keys to sidecar (not just the changed ones).
            // Non-fatal: keys are saved to DB even if the sidecar is unreachable.
            $providerStatus = [];
            $sidecarWarning = null;
            try {
                $allKeys        = $this->settings->getRawApiKeys();
                $pushResult     = $this->pushKeysToSidecar($allKeys);
                $providerStatus = $pushResult['providers'] ?? [];
            } catch (\Throwable $e) {
                $sidecarWarning = 'Keys saved but could not push to sidecar: ' . $e->getMessage();
            }

            $result = [
                'status'    => 'saved',
                'keys'      => $this->settings->getApiKeys(),
                'providers' => $providerStatus,
            ];
            if ($sidecarWarning) {
                $result['warning'] = $sidecarWarning;
            }

            return $this->json($response, $result);
        } catch (\Throwable $e) {
            return $this->json($response, [
                'error' => 'Failed to save API keys: ' . $e->getMessage(),
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

    /**
     * Push API keys to the sidecar /v1/config/keys endpoint.
     */
    private function pushKeysToSidecar(array $keys): array
    {
        $baseUrl = rtrim($this->llmCfg['base_url'] ?? '', '/');
        $secret  = $this->llmCfg['api_secret'] ?? '';

        $opts = [
            'http' => [
                'method'  => 'POST',
                'timeout' => 5,
                'header'  => implode("\r\n", array_filter([
                    'Content-Type: application/json',
                    $secret ? "Authorization: Bearer {$secret}" : '',
                ])),
                'content' => json_encode($keys),
            ],
        ];
        $ctx = stream_context_create($opts);
        $raw = @file_get_contents("{$baseUrl}/v1/config/keys", false, $ctx);

        if ($raw === false) {
            throw new \RuntimeException('Failed to reach sidecar config endpoint');
        }

        return json_decode($raw, true) ?? [];
    }

    /**
     * Fetch provider status from the sidecar /health endpoint.
     */
    private function fetchSidecarProviderStatus(): array
    {
        $baseUrl = rtrim($this->llmCfg['base_url'] ?? '', '/');
        $secret  = $this->llmCfg['api_secret'] ?? '';

        $opts = [
            'http' => [
                'timeout' => 3,
                'header'  => $secret ? "Authorization: Bearer {$secret}\r\n" : '',
            ],
        ];
        $ctx = stream_context_create($opts);
        $raw = @file_get_contents("{$baseUrl}/health", false, $ctx);

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true) ?? [];
        return $data['providers'] ?? [];
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
