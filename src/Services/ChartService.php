<?php
// src/Services/ChartService.php
declare(strict_types=1);

namespace App\Services;

use App\Llm\AbstractLlmClient;

class ChartService
{
    private AbstractLlmClient $llm;

    public function __construct(AbstractLlmClient $llm)
    {
        $this->llm = $llm;
    }

    public function analyzeDataForCharts(array $dbResult, string $intent, string $originalQuery = ''): ?array
    {
        if ($dbResult['count'] === 0 || empty($dbResult['rows'])) {
            return null;
        }

        $rows = $dbResult['rows'];

        // Don't suggest charts for very large datasets
        if (count($rows) > 1000) {
            return null;
        }

        // Don't suggest charts for very small datasets unless they're meaningful
        if (count($rows) < 2) {
            return null;
        }

        $analysis = $this->analyzeDataStructure($rows);
        $suggestions = $this->getLLMChartSuggestions($analysis, $intent, $originalQuery);

        if (empty($suggestions)) {
            return null;
        }

        return [
            'suitable_for_charts' => true,
            'suggestions' => $suggestions,
            'data_analysis' => $analysis
        ];
    }

    private function analyzeDataStructure(array $rows): array
    {
        $firstRow = $rows[0];
        $columns = array_keys($firstRow);
        $rowCount = count($rows);

        $analysis = [
            'row_count' => $rowCount,
            'column_count' => count($columns),
            'columns' => [],
            //'sample_data' => array_slice($rows, 0, 3),
            'sample_data' => $rows,
            'hints' => [
                'has_facility' => false,
                'has_year' => false,
                'likely_category_cols' => [],
                'likely_numeric_cols' => [],
            ],
        ];

        foreach ($columns as $column) {
            $values = array_column($rows, $column);
            $uniqueValues = array_values(array_unique($values, SORT_REGULAR));
            $type = $this->detectColumnType($values);

            // basic stats for numerical
            $min = $max = $sum = null;
            if (in_array($type, ['integer', 'float', 'numeric'])) {
                $nums = array_values(array_map(fn($v) => is_numeric($v) ? (float)$v : null, $values));
                $nums = array_values(array_filter($nums, fn($v) => $v !== null));
                if ($nums) {
                    $min = min($nums);
                    $max = max($nums);
                    $sum = array_sum($nums);
                }
            }

            $analysis['columns'][$column] = [
                'type' => $type,
                'unique_count' => count($uniqueValues),
                'sample_values' => array_slice($uniqueValues, 0, 5),
                'all_values' => count($values) <= 20 ? $values : array_slice($values, 0, 20),
                'stats' => [
                    'min' => $min,
                    'max' => $max,
                    'sum' => $sum,
                ]
            ];

            // heuristics for roles
            $lname = strtolower($column);
            if (in_array($type, ['string', 'year']) && $analysis['columns'][$column]['unique_count'] <= 200) {
                $analysis['hints']['likely_category_cols'][] = $column;
            }
            if (in_array($type, ['integer', 'float', 'numeric'])) {
                $analysis['hints']['likely_numeric_cols'][] = $column;
            }
            if (preg_match('/facility|site|location|clinic/i', $column)) {
                $analysis['hints']['has_facility'] = true;
            }
            if ($type === 'year' || preg_match('/^year$|fy|fiscal/i', $lname)) {
                $analysis['hints']['has_year'] = true;
            }
        }

        return $analysis;
    }


    private function getLLMChartSuggestions(array $analysis, string $intent, string $query): array
    {
        $prompt = $this->buildChartAnalysisPrompt($analysis, $intent, $query);

        try {
            $response = $this->llm->generateJson($prompt, 1200);
            // Some models return JSON-with-prose; strip non-JSON safely
            $json = $this->extractJson($response);
            $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (isset($parsed['suggestions']) && is_array($parsed['suggestions'])) {
                return $this->processLLMSuggestions($parsed['suggestions']);
            }
        } catch (\Throwable $e) {
            error_log("Chart LLM analysis failed: " . $e->getMessage());
        }

        return $this->getFallbackSuggestions($analysis);
    }

    private function extractJson(string $s): string
    {
        // naive but effective: find first { and last } block
        $start = strpos($s, '{');
        $end   = strrpos($s, '}');
        if ($start === false || $end === false || $end <= $start) return $s;
        return substr($s, $start, $end - $start + 1);
    }


    private function buildChartAnalysisPrompt(array $analysis, string $intent, string $query): string
    {
        $columnInfo = '';
        foreach ($analysis['columns'] as $column => $info) {
            $samples = implode(', ', array_map(fn($v) => (string)$v, array_slice($info['sample_values'], 0, 3)));
            $columnInfo .= "- {$column}: type={$info['type']}, unique={$info['unique_count']}, samples=[{$samples}]\n";
        }
        $sampleDataJson = json_encode($analysis['sample_data'], JSON_PRETTY_PRINT);

        // JSON schema-like instruction makes outputs far more reliable
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'suggestions' => [
                    'type' => 'array',
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'required' => ['type', 'title', 'x_axis', 'y_axis'],
                        'properties' => [
                            'type' => ['enum' => ['bar', 'pie', 'line', 'scatter']],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'x_axis' => ['type' => 'string'],
                            'y_axis' => ['type' => 'string'],
                            'grouping' => ['type' => 'string'],
                            'reasoning' => ['type' => 'string']
                        ]
                    ]
                ]
            ],
            'required' => ['suggestions']
        ], JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are a medical data visualization expert. Produce chart suggestions ONLY as strict JSON (no prose), validating against this schema:

SCHEMA:
{$schema}

CONTEXT
QUERY: "{$query}"
DETECTED INTENT: {$intent}
ROWS: {$analysis['row_count']}

COLUMNS
{$columnInfo}

SAMPLE DATA (first 3 rows)
{$sampleDataJson}

RULES
- Prefer grouped BAR when a facility/category + numeric + year/group column exist.
- Prefer standard BAR when a single category + numeric column exist.
- PIE only if categories are reasonably few (<= 12) and show share of total.
- LINE only if the X-axis is temporal or year-like and sorted ascending.
- Never propose scatter for pure counts with categorical X-axis.
- Always return field names that exist in the table (from COLUMNS above).
- Titles must be concise and human-friendly.

Return JSON only.
PROMPT;
    }


    private function processLLMSuggestions(array $suggestions): array
    {
        $processedSuggestions = [];

        foreach (array_slice($suggestions, 0, 3) as $suggestion) {
            if (!isset($suggestion['type']) || !isset($suggestion['x_axis']) || !isset($suggestion['y_axis'])) {
                continue;
            }

            $chartType = $suggestion['type'];
            $config = null;

            switch ($chartType) {
                case 'bar':
                    $config = $this->generateBarChartConfig(
                        $suggestion['x_axis'],
                        $suggestion['y_axis'],
                        $suggestion['grouping'] ?? null
                    );
                    break;
                case 'pie':
                    $config = $this->generatePieChartConfig(
                        $suggestion['x_axis'],
                        $suggestion['y_axis']
                    );
                    break;
                case 'line':
                    $config = $this->generateLineChartConfig(
                        $suggestion['x_axis'],
                        $suggestion['y_axis']
                    );
                    break;
                case 'scatter':
                    $config = $this->generateScatterChartConfig(
                        $suggestion['x_axis'],
                        $suggestion['y_axis']
                    );
                    break;
                default:
                    continue 2; // Skip invalid chart types
            }

            if ($config) {
                $processedSuggestions[] = [
                    'type' => $chartType,
                    'title' => $suggestion['title'] ?? ucfirst($chartType) . ' Chart',
                    'description' => $suggestion['description'] ?? 'Visualize your data',
                    'config' => $config
                ];
            }
        }

        return $processedSuggestions;
    }

    private function getFallbackSuggestions(array $analysis): array
    {
        $cats = $analysis['hints']['likely_category_cols'];
        $nums = $analysis['hints']['likely_numeric_cols'];
        if (!$cats || !$nums) return [];

        // Prefer facility_name-like + year if present
        $facilityCol = null;
        foreach ($cats as $c) {
            if (preg_match('/facility|site|location|clinic/i', $c)) {
                $facilityCol = $c;
                break;
            }
        }
        $yearCol = null;
        foreach ($analysis['columns'] as $c => $info) {
            if ($info['type'] === 'year' || preg_match('/^year$|fy|fiscal/i', strtolower($c))) {
                $yearCol = $c;
                break;
            }
        }

        $cat = $facilityCol ?: $cats[0];
        $num = $nums[0];

        $suggestions = [];

        // Grouped bar if year present
        if ($yearCol) {
            $suggestions[] = [
                'type' => 'bar',
                'title' => 'Counts by Facility (Grouped by Year)',
                'description' => 'Compare values across facilities with yearly grouping',
                'config' => $this->generateBarChartConfig($cat, $num, $yearCol),
            ];
        } else {
            $suggestions[] = [
                'type' => 'bar',
                'title' => 'Counts by Category',
                'description' => 'Compare values across categories',
                'config' => $this->generateBarChartConfig($cat, $num, null),
            ];
        }

        // Pie if categories are not too many
        $uc = $analysis['columns'][$cat]['unique_count'] ?? PHP_INT_MAX;
        if ($uc <= 12) {
            $suggestions[] = [
                'type' => 'pie',
                'title' => 'Share by Category',
                'description' => 'Proportional distribution',
                'config' => $this->generatePieChartConfig($cat, $num),
            ];
        }

        return $suggestions;
    }


    private function generateBarChartConfig(string $xColumn, string $yColumn, ?string $groupingColumn = null): array
    {
        return [
            'chart_type' => 'bar',
            'x_axis' => $xColumn,
            'y_axis' => $yColumn,
            'grouping_column' => $groupingColumn,
            'echarts_option' => [
                'tooltip' => [
                    'trigger' => $groupingColumn ? 'axis' : 'item',
                    'axisPointer' => ['type' => 'shadow']
                ],
                'legend' => $groupingColumn ? ['top' => 'top'] : ['show' => false],
                'grid' => [
                    'left' => '10%',
                    'right' => '10%',
                    'bottom' => '22%',
                    'top' => '15%',
                    'containLabel' => true
                ],
                'xAxis' => [
                    'type' => 'category',
                    'name' => ucfirst(str_replace('_', ' ', $xColumn)),
                    'axisLabel' => ['interval' => 0, 'rotate' => 45, 'fontSize' => 11]
                ],
                'yAxis' => [
                    'type' => 'value',
                    'name' => ucfirst(str_replace('_', ' ', $yColumn)),
                    'axisLabel' => ['fontSize' => 11]
                ],
                'series' => [] // filled at runtime
            ]
        ];
    }


    private function generatePieChartConfig(string $labelColumn, string $valueColumn): array
    {
        return [
            'chart_type' => 'pie',
            'label_column' => $labelColumn,
            'value_column' => $valueColumn,
            'echarts_option' => [
                'tooltip' => [
                    'trigger' => 'item',
                    'formatter' => '{a} <br/>{b}: {c} ({d}%)'
                ],
                'legend' => [
                    'orient' => 'vertical',
                    'left' => 'left'
                ],
                'series' => [[
                    'name' => ucfirst(str_replace('_', ' ', $valueColumn)),
                    'type' => 'pie',
                    'radius' => '50%',
                    'emphasis' => [
                        'itemStyle' => [
                            'shadowBlur' => 10,
                            'shadowOffsetX' => 0,
                            'shadowColor' => 'rgba(0, 0, 0, 0.5)'
                        ]
                    ]
                ]]
            ]
        ];
    }

    private function generateLineChartConfig(string $xColumn, string $yColumn): array
    {
        return [
            'chart_type' => 'line',
            'x_axis' => $xColumn,
            'y_axis' => $yColumn,
            'echarts_option' => [
                'tooltip' => [
                    'trigger' => 'axis'
                ],
                'xAxis' => [
                    'type' => 'category',
                    'name' => ucfirst(str_replace('_', ' ', $xColumn)),
                    'boundaryGap' => false
                ],
                'yAxis' => [
                    'type' => 'value',
                    'name' => ucfirst(str_replace('_', ' ', $yColumn))
                ],
                'series' => [[
                    'type' => 'line',
                    'name' => ucfirst(str_replace('_', ' ', $yColumn)),
                    'smooth' => true,
                    'itemStyle' => [
                        'color' => '#5470c6'
                    ]
                ]]
            ]
        ];
    }

    private function generateScatterChartConfig(string $xColumn, string $yColumn): array
    {
        return [
            'chart_type' => 'scatter',
            'x_axis' => $xColumn,
            'y_axis' => $yColumn,
            'echarts_option' => [
                'tooltip' => [
                    'trigger' => 'item'
                ],
                'xAxis' => [
                    'type' => 'value',
                    'name' => ucfirst(str_replace('_', ' ', $xColumn))
                ],
                'yAxis' => [
                    'type' => 'value',
                    'name' => ucfirst(str_replace('_', ' ', $yColumn))
                ],
                'series' => [[
                    'type' => 'scatter',
                    'name' => 'Data Points',
                    'itemStyle' => [
                        'color' => '#5470c6'
                    ]
                ]]
            ]
        ];
    }

    public function formatDataForECharts(array $rows, array $chartConfig): array
    {
        switch ($chartConfig['chart_type']) {
            case 'bar':
            case 'line':
                return $this->formatCategoryValueData($rows, $chartConfig);
            case 'pie':
                return $this->formatPieData($rows, $chartConfig);
            case 'scatter':
                return $this->formatScatterData($rows, $chartConfig);
            default:
                return [];
        }
    }

    private function formatCategoryValueData(array $rows, array $config): array
    {
        $x = $config['x_axis'];
        $y = $config['y_axis'];
        $g = $config['grouping_column'] ?? null;

        // Normalize keys case-insensitively
        $norm = function (array $row, string $key) {
            foreach ($row as $k => $v) {
                if (strcasecmp($k, $key) === 0) return $v;
            }
            return $row[$key] ?? null;
        };

        // Aggregate duplicates (sum values for same category/group)
        $agg = [];
        if ($g) {
            foreach ($rows as $r) {
                $cat = (string)$norm($r, $x);
                $grp = (string)$norm($r, $g);
                $val = (float)$norm($r, $y);
                if (!isset($agg[$grp])) $agg[$grp] = [];
                if (!isset($agg[$grp][$cat])) $agg[$grp][$cat] = 0.0;
                $agg[$grp][$cat] += $val;
            }

            // unify category list
            $allCats = [];
            foreach ($agg as $grp => $pairs) {
                foreach ($pairs as $cat => $_) $allCats[$cat] = true;
            }
            $categories = array_keys($allCats);

            // sort categories by total descending
            $totals = array_fill_keys($categories, 0.0);
            foreach ($agg as $grp => $pairs) {
                foreach ($pairs as $cat => $val) $totals[$cat] += $val;
            }
            arsort($totals);
            $categories = array_keys($totals);

            // cap categories (top 30 -> rest to "Other")
            $cap = 30;
            if (count($categories) > $cap) {
                $top = array_slice($categories, 0, $cap);
                $other = 'Other';
                $categories = array_merge($top, [$other]);

                // fold others
                foreach ($agg as $grp => $pairs) {
                    $folded = array_fill_keys($categories, 0.0);
                    foreach ($pairs as $cat => $val) {
                        $folded[in_array($cat, $top, true) ? $cat : $other] += $val;
                    }
                    $agg[$grp] = $folded;
                }
            } else {
                // ensure every category exists per group
                foreach ($agg as $grp => $pairs) {
                    foreach ($categories as $cat) {
                        if (!isset($agg[$grp][$cat])) $agg[$grp][$cat] = 0.0;
                    }
                }
            }

            // build series
            $series = [];
            foreach ($agg as $grp => $pairs) {
                $series[] = [
                    'name' => (string)$grp,
                    'type' => $config['chart_type'],
                    'data' => array_map(fn($c) => (float)$agg[$grp][$c], $categories),
                ];
            }

            return ['categories' => $categories, 'series' => $series];
        }

        // Single series
        $bucket = [];
        foreach ($rows as $r) {
            $cat = (string)$norm($r, $x);
            $val = (float)$norm($r, $y);
            if (!isset($bucket[$cat])) $bucket[$cat] = 0.0;
            $bucket[$cat] += $val;
        }

        // sort desc by value
        arsort($bucket);

        // cap to top 30 + Other
        $cap = 30;
        if (count($bucket) > $cap) {
            $top = array_slice($bucket, 0, $cap, true);
            $other = array_sum(array_slice($bucket, $cap, null, true));
            $top['Other'] = $other;
            $bucket = $top;
        }

        return [
            'categories' => array_keys($bucket),
            'values' => array_values($bucket),
        ];
    }


    private function formatPieData(array $rows, array $config): array
    {
        $label = $config['label_column'];
        $value = $config['value_column'];

        $bucket = [];
        foreach ($rows as $r) {
            $k = (string)($r[$label] ?? '');
            $v = (float)($r[$value] ?? 0);
            if (!isset($bucket[$k])) $bucket[$k] = 0.0;
            $bucket[$k] += $v;
        }

        arsort($bucket);
        $cap = 20; // pies get busy quickly
        if (count($bucket) > $cap) {
            $top = array_slice($bucket, 0, $cap, true);
            $other = array_sum(array_slice($bucket, $cap, null, true));
            $top['Other'] = $other;
            $bucket = $top;
        }

        $data = [];
        foreach ($bucket as $k => $v) $data[] = ['name' => $k, 'value' => $v];
        return ['data' => $data];
    }


    private function formatScatterData(array $rows, array $config): array
    {
        $xColumn = $config['x_axis'];
        $yColumn = $config['y_axis'];

        $data = [];
        foreach ($rows as $row) {
            $data[] = [(float)$row[$xColumn], (float)$row[$yColumn]];
        }

        return ['data' => $data];
    }

    private function detectColumnType(array $values): string
    {
        // Skip nulls when inferring
        $nonNull = array_values(array_filter($values, fn($v) => $v !== null && $v !== ''));
        if (!$nonNull) return 'null';

        // Try integers/floats/numeric strings
        $numericCount = 0;
        $intLikeCount = 0;
        $temporalCount = 0;
        $yearLikeCount = 0;

        $sample = array_slice($nonNull, 0, min(25, count($nonNull)));
        foreach ($sample as $v) {
            $sv = is_string($v) ? trim($v) : $v;

            // Year-like (e.g., 2020, '2024')
            if (preg_match('/^\d{4}$/', (string)$sv) && (int)$sv >= 1900 && (int)$sv <= 2100) {
                $yearLikeCount++;
            }

            // Temporal: ISO date or yyyy-mm, yyyy-mm-dd, with optional time
            if ($this->isTemporalValue($sv)) {
                $temporalCount++;
            }

            // Numeric?
            if (is_int($sv)) {
                $numericCount++;
                $intLikeCount++;
                continue;
            }
            if (is_float($sv)) {
                $numericCount++;
                continue;
            }
            if (is_string($sv) && is_numeric($sv)) {
                $numericCount++;
                if ((string)(int)$sv === (string)$sv) $intLikeCount++;
            }
        }

        // Decide type
        if ($temporalCount >= max(1, floor(count($sample) * 0.6))) return 'temporal';
        if ($yearLikeCount >= max(1, floor(count($sample) * 0.7))) return 'year';
        if ($numericCount >= max(1, floor(count($sample) * 0.7))) {
            // Heuristic: mostly integers => integer, else float
            return ($intLikeCount >= $numericCount * 0.8) ? 'integer' : 'float';
        }
        return 'string';
    }

    private function isTemporalValue($v): bool
    {
        if ($v instanceof \DateTimeInterface) return true;
        if (!is_string($v)) return false;
        $s = trim($v);

        // yyyy-mm, yyyy-mm-dd, with optional time
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])(-([0-2]\d|3[01]))?([ T]\d{2}:\d{2}(:\d{2})?)?$/', $s)) return true;

        // Common alt formats (be conservative)
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) return true; // mm/dd/yyyy
        if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $s)) return true; // yyyy/mm/dd
        return false;
    }
}
