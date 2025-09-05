<?php
declare(strict_types=1);

namespace App\Llm;

use RuntimeException;
use GuzzleHttp\Client;
use App\Llm\AbstractLlmClient;


final class AnthropicClient extends AbstractLlmClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly int $timeout = 30
    ) {}

    public function identity(): array
    {
        return ['provider' => 'anthropic', 'model' => $this->model];
    }

    protected function generateRawResponse(string $prompt): string
    {
        return $this->message($prompt, 1200, 0.1);
    }

    public function generateJson(string $prompt, int $maxTokens = 200): string
    {
        return trim($this->message($prompt, $maxTokens, 0.0));
    }

    private function message(string $prompt, int $maxTokens, float $temperature): string
    {
        $http = new Client(['base_uri' => rtrim($this->baseUrl, '/').'/', 'timeout' => $this->timeout, 'connect_timeout' => 8]);
        $resp = $http->post('v1/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'json' => [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'stop_sequences' => ['Q:', 'Explanation:', 'Note:'],
            ],
        ]);

        $data = json_decode((string)$resp->getBody(), true);
        $text = trim($data['content'][0]['text'] ?? '');
        if ($text === '') {
            throw new RuntimeException('Empty Anthropic response');
        }
        return $text;
    }
}