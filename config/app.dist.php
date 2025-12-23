<?php
declare(strict_types=1);

return [
    // existing
    'allow_all'     => true,
    'db_name'       => 'vlsm',
    'schema_path'   => __DIR__ . '/../var/schema.json',
    'default_limit' => 200,

    // default provider + models
    'llm' => [
        // 'ollama' | 'openai' | 'anthropic'
        'provider' => getenv('LLM_PROVIDER') ?: 'openai',

        // allow per-provider defaults (can be overridden by request)
        'providers' => [
            'ollama' => [
                'base_url' => getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434',
                'model'    => getenv('OLLAMA_MODEL') ?: 'codegemma:7b-instruct',
                'timeout'  => 30,
            ],
            'openai' => [
                'api_key'  => getenv('OPENAI_API_KEY') ?: '',
                'base_url' => getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com',
                // pick a contemporary small/cheap reasoning/code model you have access to
                'model'    => getenv('OPENAI_MODEL') ?: 'gpt-4o-mini',
                'timeout'  => 30,
            ],
            'anthropic' => [
                'api_key'  => getenv('ANTHROPIC_API_KEY') ?: '',
                'base_url' => getenv('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com',
                // pick a contemporary small/cheap reasoning/code model you have access to
                'model'    => getenv('ANTHROPIC_MODEL') ?: 'claude-opus-4-1-20250805',
                'timeout'  => 30,
            ],
        ],
        // Optional overrides per step. If omitted, use the default provider+model above.
        'routing' => [
            // 'intent' => ['provider' => 'openai'],   // will use openai + its model
            // 'sql'    => ['provider' => 'ollama'],   // will use ollama + its model
            // 'chart'  => ['provider' => 'openai'],   // will use openai + its model
        ],
    ],
];
