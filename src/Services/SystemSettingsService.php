<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;

final class SystemSettingsService
{
    private array $cache = [];

    /**
     * Get a setting value by key, with optional default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $row = SystemSetting::find($key);
            $value = $row ? $row->value : $default;
        } catch (\Throwable) {
            // Table may not exist yet — graceful fallback
            $value = $default;
        }

        $this->cache[$key] = $value;
        return $value;
    }

    /**
     * Set a setting value (upsert). Null deletes the row (revert to default).
     */
    public function set(string $key, mixed $value): void
    {
        if ($value === null) {
            SystemSetting::where('key', $key)->delete();
            unset($this->cache[$key]);
            return;
        }

        SystemSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
        $this->cache[$key] = $value;
    }

    /**
     * Get the LLM model configuration.
     *
     * @return array{default_model: ?string, step_models: array<string, ?string>}
     */
    public function getLlmModelConfig(): array
    {
        return [
            'default_model' => $this->get('llm.default_model'),
            'step_models'   => $this->get('llm.step_models', [
                'intent_detection'  => null,
                'sql_generation'    => null,
                'chart_suggestion'  => null,
            ]),
        ];
    }

    /**
     * Save LLM model configuration.
     */
    public function setLlmModelConfig(array $config): void
    {
        if (array_key_exists('default_model', $config)) {
            $this->set('llm.default_model', $config['default_model']);
        }
        if (array_key_exists('step_models', $config)) {
            $this->set('llm.step_models', $config['step_models']);
        }
    }

    /**
     * Resolve the model for a given pipeline step.
     *
     * Priority: step-specific override > global DB override > null (LlmClient env default).
     */
    public function resolveModelForStep(string $step): ?string
    {
        $config    = $this->getLlmModelConfig();
        $stepModel = $config['step_models'][$step] ?? null;

        if ($stepModel !== null && $stepModel !== '') {
            return $stepModel;
        }

        $global = $config['default_model'] ?? null;
        return ($global !== null && $global !== '') ? $global : null;
    }
}
