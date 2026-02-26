<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Suggests chart types based on query results and plan context.
 *
 * Applies deterministic heuristics first (time series, single dimension,
 * two-value distributions) and falls back to the LLM for ambiguous cases.
 */
final class ChartService
{
    private const CHART_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'recommended' => [
                'type' => 'string',
                'enum' => ['line', 'bar', 'horizontal_bar', 'stacked_bar', 'pie', 'donut', 'area', 'scatter', 'table'],
            ],
            'alternatives' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
            'config' => [
                'type' => 'object',
                'properties' => [
                    'x_axis' => ['type' => 'string'],
                    'y_axis' => ['type' => 'string'],
                    'series' => ['type' => ['string', 'null']],
                    'title' => ['type' => 'string'],
                ],
            ],
            'reasoning' => ['type' => 'string'],
        ],
        'required' => ['recommended', 'alternatives', 'config'],
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a data-visualization advisor for a laboratory analytics platform.

Given a data profile (column names, types, row count, sample values) and
the user's query intent, recommend the best chart type and axis configuration.

Prefer simple, readable charts. Use line/area for time series, bar for
categorical comparisons, pie/donut only when there are few categories
(<=7), scatter for correlations, and table when the data has too many
dimensions for a clean chart.
PROMPT;

    /** Column names that indicate a temporal dimension. */
    private const TIME_PATTERNS = [
        '/date/i', '/month/i', '/year/i', '/week/i', '/quarter/i',
        '/period/i', '/time/i', '/day/i', '/created_at/i', '/updated_at/i',
    ];

    public function __construct(private LlmClient $llm) {}

    /**
     * Suggest chart types based on query results.
     *
     * @param  array  $result Result from DatabaseService::execute() — {columns, rows, count}.
     * @param  string $intent Detected query intent (count, list, aggregate, etc.).
     * @param  string $query  Original user question for LLM context.
     * @return array{recommended: string, alternatives: list<string>, config: array, reasoning: string}
     */
    public function suggest(array $result, string $intent = '', string $query = ''): array
    {
        $columns = $result['columns'] ?? [];
        $rows = $result['rows'] ?? [];
        $rowCount = $result['count'] ?? count($rows);

        // Empty or single-cell results are best shown as a table / KPI.
        if ($rowCount === 0 || $columns === []) {
            return $this->buildResult('table', ['table'], [], 'No data to chart.');
        }

        $profile = $this->profileData($columns, $rows);

        // ── Heuristic pass ──────────────────────────────────────────
        $heuristic = $this->applyHeuristics($profile, $rowCount);

        if ($heuristic !== null) {
            return $this->buildResult(
                $heuristic['recommended'],
                $heuristic['alternatives'],
                $this->inferConfig($profile, $heuristic['recommended']),
                $heuristic['reasoning'],
            );
        }

        // ── LLM fallback ────────────────────────────────────────────
        return $this->askLlm($profile, $rowCount, $intent, $query);
    }

    // ── Data profiling ──────────────────────────────────────────────

    /**
     * Build a lightweight profile of the result set for heuristic / LLM use.
     *
     * @return array<string, array{name: string, type: string, distinct: int, sample: list<mixed>}>
     */
    private function profileData(array $columns, array $rows): array
    {
        $profile = [];
        $sampleSize = min(count($rows), 20);
        $sampleRows = array_slice($rows, 0, $sampleSize);

        foreach ($columns as $col) {
            $values = array_column($sampleRows, $col);
            $nonNull = array_filter($values, fn($v) => $v !== null && $v !== '');

            $profile[$col] = [
                'name' => $col,
                'type' => $this->inferColumnType($col, $nonNull),
                'distinct' => count(array_unique($nonNull)),
                'sample' => array_slice($nonNull, 0, 5),
            ];
        }

        return $profile;
    }

    /**
     * Guess column type from name patterns and sample values.
     */
    private function inferColumnType(string $name, array $values): string
    {
        // Check name patterns for temporal columns.
        foreach (self::TIME_PATTERNS as $pattern) {
            if (preg_match($pattern, $name)) {
                return 'temporal';
            }
        }

        if ($values === []) {
            return 'unknown';
        }

        $allNumeric = true;
        foreach ($values as $v) {
            if (!is_numeric($v)) {
                $allNumeric = false;
                break;
            }
        }

        return $allNumeric ? 'numeric' : 'categorical';
    }

    // ── Heuristics ──────────────────────────────────────────────────

    /**
     * Apply deterministic rules. Returns null when inconclusive.
     */
    private function applyHeuristics(array $profile, int $rowCount): ?array
    {
        $types = array_column($profile, 'type');
        $temporalCols = array_keys(array_filter($profile, fn($p) => $p['type'] === 'temporal'));
        $numericCols = array_keys(array_filter($profile, fn($p) => $p['type'] === 'numeric'));
        $categoricalCols = array_keys(array_filter($profile, fn($p) => $p['type'] === 'categorical'));

        $colCount = count($profile);

        // Single numeric value (KPI / scalar) — table is fine.
        if ($rowCount === 1 && $colCount <= 2) {
            return [
                'recommended' => 'table',
                'alternatives' => ['bar'],
                'reasoning' => 'Single-row result best displayed as a KPI or table.',
            ];
        }

        // Time series: at least one temporal column + at least one numeric column.
        if ($temporalCols !== [] && $numericCols !== []) {
            $hasMultipleSeries = count($numericCols) > 1 || $categoricalCols !== [];
            return [
                'recommended' => $hasMultipleSeries ? 'area' : 'line',
                'alternatives' => ['area', 'bar', 'table'],
                'reasoning' => 'Temporal dimension detected — line/area chart is appropriate.',
            ];
        }

        // Single categorical + single numeric → bar or pie.
        if (count($categoricalCols) === 1 && count($numericCols) === 1) {
            if ($rowCount <= 7) {
                return [
                    'recommended' => 'pie',
                    'alternatives' => ['donut', 'bar', 'horizontal_bar'],
                    'reasoning' => 'Single categorical dimension with few categories suits a pie chart.',
                ];
            }
            return [
                'recommended' => 'bar',
                'alternatives' => ['horizontal_bar', 'table'],
                'reasoning' => 'Single categorical dimension with many categories suits a bar chart.',
            ];
        }

        // Two numeric columns without temporal/categorical → scatter.
        if ($categoricalCols === [] && $temporalCols === [] && count($numericCols) === 2) {
            return [
                'recommended' => 'scatter',
                'alternatives' => ['table'],
                'reasoning' => 'Two numeric dimensions suggest a scatter plot.',
            ];
        }

        // Multiple categorical + numeric → stacked bar.
        if (count($categoricalCols) >= 2 && $numericCols !== []) {
            return [
                'recommended' => 'stacked_bar',
                'alternatives' => ['horizontal_bar', 'table'],
                'reasoning' => 'Multiple categorical dimensions suit a stacked bar chart.',
            ];
        }

        // Too many columns / complex shape → table.
        if ($colCount > 6) {
            return [
                'recommended' => 'table',
                'alternatives' => ['bar'],
                'reasoning' => 'High-dimensionality data is best shown as a table.',
            ];
        }

        // Inconclusive — fall through to LLM.
        return null;
    }

    // ── Config inference ────────────────────────────────────────────

    /**
     * Infer axis configuration from the profile and chart type.
     */
    private function inferConfig(array $profile, string $chartType): array
    {
        $temporalCols = array_keys(array_filter($profile, fn($p) => $p['type'] === 'temporal'));
        $numericCols = array_keys(array_filter($profile, fn($p) => $p['type'] === 'numeric'));
        $categoricalCols = array_keys(array_filter($profile, fn($p) => $p['type'] === 'categorical'));

        $xAxis = $temporalCols[0] ?? $categoricalCols[0] ?? ($numericCols[0] ?? '');
        $yAxis = $numericCols[0] ?? '';

        // For scatter, use the two numeric columns.
        if ($chartType === 'scatter' && count($numericCols) >= 2) {
            $xAxis = $numericCols[0];
            $yAxis = $numericCols[1];
        }

        // If x_axis picked a numeric col, try to use a different one for y.
        if ($xAxis === $yAxis && count($numericCols) > 1) {
            $yAxis = $numericCols[1];
        }

        $series = null;
        if ($categoricalCols !== [] && in_array($chartType, ['stacked_bar', 'area', 'line'], true)) {
            // Use the first categorical column that is NOT the x-axis as the series.
            foreach ($categoricalCols as $cat) {
                if ($cat !== $xAxis) {
                    $series = $cat;
                    break;
                }
            }
        }

        return [
            'x_axis' => $xAxis,
            'y_axis' => $yAxis,
            'series' => $series,
            'title' => '',
        ];
    }

    // ── LLM fallback ────────────────────────────────────────────────

    private function askLlm(array $profile, int $rowCount, string $intent = '', string $query = ''): array
    {
        $profileSummary = [];
        foreach ($profile as $col) {
            $sampleStr = implode(', ', array_map('strval', $col['sample']));
            $profileSummary[] = "- {$col['name']} ({$col['type']}, {$col['distinct']} distinct): [{$sampleStr}]";
        }

        $parts = [];
        if ($query !== '') {
            $parts[] = "## User Question\n{$query}";
        }
        if ($intent !== '') {
            $parts[] = "## Detected Intent\n{$intent}";
        }
        $parts[] = "## Data Profile ({$rowCount} rows)\n" . implode("\n", $profileSummary);
        $parts[] = 'Recommend the best chart type and axis configuration for this data.';

        $userPrompt = implode("\n\n", $parts);

        try {
            $result = $this->llm->structured(
                system: self::SYSTEM_PROMPT,
                userPrompt: $userPrompt,
                schema: self::CHART_SCHEMA,
                schemaName: 'chart_suggestion',
                temperature: 0.0,
            );

            return [
                'recommended' => $result['recommended'] ?? 'table',
                'alternatives' => (array) ($result['alternatives'] ?? []),
                'config' => $result['config'] ?? ['x_axis' => '', 'y_axis' => '', 'series' => null, 'title' => ''],
                'reasoning' => $result['reasoning'] ?? '',
            ];
        } catch (\Throwable $e) {
            error_log('ChartService LLM fallback failed: ' . $e->getMessage());

            // Ultimate fallback: recommend a table.
            return $this->buildResult(
                'table',
                ['bar'],
                $this->inferConfig($profile, 'table'),
                'Fallback to table due to LLM error.',
            );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function buildResult(string $recommended, array $alternatives, array $config, string $reasoning): array
    {
        return [
            'recommended' => $recommended,
            'alternatives' => $alternatives,
            'config' => array_merge(['x_axis' => '', 'y_axis' => '', 'series' => null, 'title' => ''], $config),
            'reasoning' => $reasoning,
        ];
    }
}
