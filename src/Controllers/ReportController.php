<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Report;
use Psr\Http\Message\{ResponseInterface as Response, ServerRequestInterface as Request};
use Ramsey\Uuid\Uuid;

final class ReportController
{
    // ── GET /api/v1/reports ─────────────────────────────────────────

    public function list(Request $request, Response $response): Response
    {
        try {
            $params  = $request->getQueryParams();
            $page    = max(1, (int) ($params['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));

            $total   = Report::count();
            $reports = Report::orderBy('created_at', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return $this->json($response, [
                'data'       => $reports->toArray(),
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total'       => $total,
                    'total_pages' => (int) ceil($total / $perPage),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Failed to list reports: ' . $e->getMessage()], 500);
        }
    }

    // ── POST /api/v1/reports ────────────────────────────────────────

    public function create(Request $request, Response $response): Response
    {
        try {
            $body  = (array) $request->getParsedBody();
            $title = trim((string) ($body['title'] ?? ''));

            if ($title === '') {
                return $this->json($response, ['error' => 'Missing required field: title'], 400);
            }

            $report = Report::create([
                'id'           => Uuid::uuid4()->toString(),
                'title'        => $title,
                'plan_json'    => $body['plan_json'] ?? null,
                'chart_json'   => $body['chart_json'] ?? null,
                'access_scope' => (string) ($body['access_scope'] ?? 'private'),
            ]);

            return $this->json($response, ['id' => $report->id, 'status' => 'created'], 201);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Failed to create report: ' . $e->getMessage()], 500);
        }
    }

    // ── GET /api/v1/reports/{id} ────────────────────────────────────

    public function get(Request $request, Response $response): Response
    {
        try {
            $id = $request->getAttribute('id');

            if (!$id) {
                return $this->json($response, ['error' => 'Missing report ID'], 400);
            }

            $report = Report::find($id);

            if (!$report) {
                return $this->json($response, ['error' => 'Report not found'], 404);
            }

            return $this->json($response, $report->toArray());
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Failed to get report: ' . $e->getMessage()], 500);
        }
    }

    // ── PUT /api/v1/reports/{id} ────────────────────────────────────

    public function update(Request $request, Response $response): Response
    {
        try {
            $id = $request->getAttribute('id');

            if (!$id) {
                return $this->json($response, ['error' => 'Missing report ID'], 400);
            }

            $report = Report::find($id);

            if (!$report) {
                return $this->json($response, ['error' => 'Report not found'], 404);
            }

            $body           = (array) $request->getParsedBody();
            $allowedFields  = ['title', 'plan_json', 'chart_json', 'access_scope'];
            $updates         = array_intersect_key($body, array_flip($allowedFields));

            if (empty($updates)) {
                return $this->json($response, ['error' => 'No updatable fields provided'], 400);
            }

            $report->update($updates);

            return $this->json($response, ['id' => $id, 'status' => 'updated']);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Failed to update report: ' . $e->getMessage()], 500);
        }
    }

    // ── DELETE /api/v1/reports/{id} ─────────────────────────────────

    public function delete(Request $request, Response $response): Response
    {
        try {
            $id = $request->getAttribute('id');

            if (!$id) {
                return $this->json($response, ['error' => 'Missing report ID'], 400);
            }

            $deleted = Report::destroy($id);

            if ($deleted === 0) {
                return $this->json($response, ['error' => 'Report not found'], 404);
            }

            return $this->json($response, ['id' => $id, 'status' => 'deleted']);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Failed to delete report: ' . $e->getMessage()], 500);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
