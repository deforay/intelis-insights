<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;

/**
 * PHP client for the RAG sidecar (FastAPI + Qdrant).
 *
 * Endpoints:
 *   POST /v1/search  – semantic search over indexed snippets
 *   POST /v1/upsert  – batch index snippets
 *   POST /v1/reset   – clear collection
 *   POST /v1/delete  – remove specific snippets
 */
final class RagClient
{
    private Client $http;
    private bool $enabled;

    public function __construct(array $cfg)
    {
        $this->enabled = $cfg['enabled'] ?? true;
        $this->http = new Client([
            'base_uri' => rtrim($cfg['base_url'], '/') . '/',
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);
    }

    /**
     * Semantic search. Returns array of {id, type, text, meta, score}.
     */
    public function search(string $query, int $k = 8, array $filters = []): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $resp = $this->http->post('v1/search', [
                'json' => [
                    'query' => $query,
                    'k' => $k,
                    'filters' => $filters,
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            return $data['contexts'] ?? [];
        } catch (\Throwable $e) {
            // RAG failure should not break the main flow
            error_log('RAG search failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Batch upsert snippets into the vector store.
     */
    public function upsert(array $items): array
    {
        $resp = $this->http->post('v1/upsert', [
            'json' => ['items' => $items],
        ]);
        return json_decode((string) $resp->getBody(), true);
    }

    /**
     * Clear the entire collection.
     */
    public function reset(): array
    {
        $resp = $this->http->post('v1/reset');
        return json_decode((string) $resp->getBody(), true);
    }

    /**
     * Delete specific snippets by ID.
     */
    public function delete(array $ids): array
    {
        $resp = $this->http->post('v1/delete', [
            'json' => ['ids' => $ids],
        ]);
        return json_decode((string) $resp->getBody(), true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
