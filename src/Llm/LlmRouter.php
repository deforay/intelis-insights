<?php
// src/Llm/LlmRouter.php
declare(strict_types=1);

namespace App\Llm;

use RuntimeException;

final class LlmRouter
{
    /** @var array */
    private array $cfg;

    /** @var array<string,AbstractLlmClient> */
    private array $cache = [];

    public function __construct(array $appCfg)
    {
        $this->cfg = $appCfg['llm'] ?? [];
        if (empty($this->cfg['providers'])) {
            throw new RuntimeException('LLM providers not configured');
        }
    }

    /**
     * Resolve a client for a logical step (e.g., 'intent', 'sql', 'chart').
     * Optional per-request override: ['provider' => 'openai', 'model' => 'gpt-4o-mini']
     */
    public function client(string $step, ?array $override = null): AbstractLlmClient
    {
        $key = $this->cacheKey($step, $override);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Step mapping from config
        $routing   = $this->cfg['routing'] ?? [];
        $stepCfg   = $routing[$step] ?? [];
        $provider  = $override['provider'] ?? $stepCfg['provider'] ?? ($this->cfg['provider'] ?? null);
        $model     = $override['model']    ?? $stepCfg['model']    ?? null;

        if (!$provider) {
            throw new RuntimeException("No provider resolved for step '{$step}'");
        }

        $provCfg = $this->cfg['providers'][$provider] ?? null;
        if (!$provCfg) {
            throw new RuntimeException("Unsupported LLM provider: {$provider}");
        }

        $timeout = (int)($provCfg['timeout'] ?? 30);

        $client = match ($provider) {
            'ollama'    => new OllamaClient($provCfg['base_url'], $model ?? ($provCfg['model'] ?? ''), $timeout),
            'openai'    => new OpenAIClient($provCfg['api_key'] ?? '', $model ?? ($provCfg['model'] ?? ''), $provCfg['base_url'] ?? 'https://api.openai.com', $timeout),
            'anthropic' => new AnthropicClient($provCfg['api_key'] ?? '', $model ?? ($provCfg['model'] ?? ''), $provCfg['base_url'] ?? 'https://api.anthropic.com', $timeout),
            default     => throw new RuntimeException("Unknown provider: {$provider}"),
        };

        return $this->cache[$key] = $client;
    }

    /** Inspect what was resolved for a step (for debugging/telemetry) */
    public function identity(string $step, ?array $override = null): array
    {
        $routing   = $this->cfg['routing'] ?? [];
        $stepCfg   = $routing[$step] ?? [];
        $provider  = $override['provider'] ?? $stepCfg['provider'] ?? ($this->cfg['provider'] ?? null);
        $model     = $override['model']    ?? $stepCfg['model']    ?? ($this->cfg['providers'][$provider]['model'] ?? null);

        return ['step' => $step, 'provider' => $provider ?? 'unknown', 'model' => $model ?? 'unknown'];
    }

    private function cacheKey(string $step, ?array $override): string
    {
        $p = $override['provider'] ?? '';
        $m = $override['model'] ?? '';
        return "{$step}::{$p}::{$m}";
    }
}
