<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;

final class RetrieverService
{
    private Client $http;
    private string $base;

    public function __construct(string $baseUrl = 'http://127.0.0.1:8089')
    {
        $this->base = rtrim($baseUrl, '/');
        $this->http = new Client(['timeout' => 3.0]);
    }

    /** @return array{contexts: list<array{id:string,type:string,text:string,meta:array,score:float}>, debug?:array} */
    public function search(string $query, int $k = 8, array $filters = []): array
    {
        $resp = $this->http->post($this->base . '/v1/search', [
            'json' => ['query' => $query, 'k' => $k, 'filters' => $filters, 'hybrid' => true],
        ]);
        $data = json_decode((string)$resp->getBody(), true);
        return is_array($data) ? $data : ['contexts' => []];
    }
}
