<?php
declare(strict_types=1);

namespace App\Llm;

use RuntimeException;
use GuzzleHttp\Client;
use App\Llm\AbstractLlmClient;


final class OpenAIClient extends AbstractLlmClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://api.openai.com',
        private readonly int $timeout = 30
    ) {}

    public function identity(): array
    {
        return ['provider' => 'openai', 'model' => $this->model];
    }

    protected function generateRawResponse(string $prompt): string
    {
        return $this->chat($prompt, 1200, 0.1);
    }

    public function generateJson(string $prompt, int $maxTokens = 200): string
    {
        return trim($this->chat($prompt, $maxTokens, 0.0));
    }

    private function chat(string $prompt, int $maxTokens, float $temperature): string
    {
        $http = new Client(['base_uri' => rtrim($this->baseUrl, '/').'/', 'timeout' => $this->timeout, 'connect_timeout' => 8]);

        $resp = $http->post('v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer '.$this->apiKey],
            'json' => [
                'model' => $this->model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a MySQL and SQL expert. We are working on a medical database from LIS. Return only the requested format.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'stop' => ['Q:', 'Explanation:', 'Note:'],
            ],
        ]);

        $data = json_decode((string)$resp->getBody(), true);
        $text = trim($data['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            throw new RuntimeException('Empty OpenAI response');
        }
        return $text;
    }
}