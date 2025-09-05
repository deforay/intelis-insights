<?php
declare(strict_types=1);

namespace App\Llm;

use GuzzleHttp\Client;
use RuntimeException;

final class OllamaClient extends AbstractLlmClient
{
    public function __construct(
        private readonly string $baseUrl,   // e.g. http://127.0.0.1:11434
        private readonly string $model,
        private readonly int $timeout = 30
    ) {}

    public function identity(): array
    {
        return ['provider' => 'ollama', 'model' => $this->model];
    }

    protected function generateRawResponse(string $prompt): string
    {
        $http = new Client(['base_uri' => rtrim($this->baseUrl, '/').'/', 'timeout' => $this->timeout, 'connect_timeout' => 5]);
        $resp = $http->post('api/generate', [
            'json' => [
                'model'   => $this->model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => [
                    'temperature'   => 0.1,
                    'top_p'         => 0.5,
                    'repeat_penalty'=> 1.0,
                    'num_predict'   => 1000,
                    'stop'          => [';', 'Q:', 'Explanation:', 'Note:'],
                ],
            ],
        ]);

        $data = json_decode((string)$resp->getBody(), true);
        $text = trim($data['response'] ?? '');
        if ($text === '') {
            throw new RuntimeException('Empty Ollama response');
        }
        return $text;
    }

    public function generateJson(string $prompt, int $maxTokens = 200): string
    {
        $http = new Client(['base_uri' => rtrim($this->baseUrl, '/').'/', 'timeout' => $this->timeout, 'connect_timeout' => 5]);
        $resp = $http->post('api/generate', [
            'json' => [
                'model'   => $this->model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => [
                    'temperature' => 0.0,
                    'num_predict' => $maxTokens,
                    'stop'        => ['```'],
                ],
            ],
        ]);

        $data = json_decode((string)$resp->getBody(), true);
        return trim($data['response'] ?? '');
    }
}