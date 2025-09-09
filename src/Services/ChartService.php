<?php
// src/Services/ChartService.php
declare(strict_types=1);

namespace App\Services;

use App\Llm\AbstractLlmClient;

class ChartService
{
    private AbstractLlmClient $llm;
    private int $maxSuggestions = 6;

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
        $sampleValues = implode(', ', array_slice($info['sample_values'], 0, 3));
        $columnInfo .= "- {$column}: {$info['type']}, {$info['unique_count']} unique, samples: {$sampleValues}\n";
    }

    $sampleDataJson = json_encode($analysis['sample_data'], JSON_PRETTY_PRINT);

    // Tight JSON spec the model must follow
    return <<<PROMPT
You are a medical data visualization expert. Analyze this DB result and propose chart configurations.

QUERY: "{$query}"
DETECTED INTENT: {$intent}
ROW COUNT: {$analysis['row_count']}

COLUMNS:
{$columnInfo}

SAMPLE DATA (first 3 rows):
{$sampleDataJson}

Return ONLY valid JSON (no prose) with this shape:

{
  "suggestions": [
    {
      "type": "bar" | "line" | "pie" | "scatter",
      "title": "Short human title",
      "description": "One sentence on what this shows",
      "x_axis": "column_name",        // for bar/line/scatter
      "y_axis": "column_name",        // for bar/line/scatter
      "grouping": "column_name",      // optional (e.g., "year") for grouped bars
      // OPTIONAL quality knobs:
      "aggregate": "sum" | "avg" | "count",
      "normalize": "absolute" | "percent" | "rate_per_k",
      "per_k": 1000,                  // used only if normalize == "rate_per_k"
      "top_n": 20,                    // cap number of categories; fold rest into "Other"
      "sort_by": "value_desc" | "value_asc" | "alpha",
      "time_bin": "auto" | "year" | "quarter" | "month" // if x is temporal
    }
  ]
}

Rules of thumb:
- Facilities + counts + years → Prefer grouped BAR: x=facility_name, y=total_tests, grouping=year.
- PIE is acceptable to show share by facility.
- Only use LINE if x is clearly temporal; otherwise avoid.
- Never use SCATTER for simple counts by category.

Return JSON only.
PROMPT;
}



    private function processLLMSuggestions(array $suggestions): array
    {
        $processedSuggestions = [];
        $limit = (int)($_ENV['CHART_SUGGESTION_LIMIT'] ?? $this->maxSuggestions);


        foreach (array_slice($suggestions, 0, $limit) as $suggestion) {
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


    private function generateBarChartConfig(string $xColumn, string $yColumn, ?string $groupingColumn = null, array $opts = []): array
    {
        // knobs (all optional)
        $aggregate = $opts['aggregate'] ?? 'sum';             // sum|avg|count
        $normalize = $opts['normalize'] ?? 'absolute';        // absolute|percent|rate_per_k
        $perK      = $opts['per_k'] ?? 1000;                  // used if rate_per_k
        $topN      = $opts['top_n'] ?? 30;                    // cap categories
        $sortBy    = $opts['sort_by'] ?? 'value_desc';        // value_desc|value_asc|alpha
        $timeBin   = $opts['time_bin'] ?? null;               // auto|year|quarter|month (when X is temporal)

        $yFormatter = ($normalize === 'percent') ? '{value}%' : null;

        return [
            'chart_type' => 'bar',
            'x_axis' => $xColumn,
            'y_axis' => $yColumn,
            'grouping_column' => $groupingColumn,
            'aggregate' => $aggregate,
            'normalize' => $normalize,
            'per_k' => $perK,
            'top_n' => $topN,
            'sort_by' => $sortBy,
            'time_bin' => $timeBin,
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
                    'axisLabel' => ['fontSize' => 11] + ($yFormatter ? ['formatter' => $yFormatter] : [])
                ],
                'series' => []
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

        $aggregate = $config['aggregate'] ?? 'sum';           // sum|avg|count
        $normalize = $config['normalize'] ?? 'absolute';      // absolute|percent|rate_per_k
        $perK      = $config['per_k'] ?? 1000;
        $topN      = max(1, (int)($config['top_n'] ?? 30));
        $sortBy    = $config['sort_by'] ?? 'value_desc';      // value_desc|value_asc|alpha
        $timeBin   = $config['time_bin'] ?? null;             // auto|year|quarter|month

        // ---- helpers ----
        $resolve = function (array $row, string $key) {
            foreach ($row as $k => $v) if (strcasecmp($k, $key) === 0) return $v;
            return $row[$key] ?? null;
        };
        $canon = fn($s) => trim(preg_replace('/\s+/', ' ', (string)$s));
        $isYear = fn($v) => (is_numeric($v) && (int)$v >= 1900 && (int)$v <= 2100);
        $parseTemporal = function ($v) {
            $s = (string)$v;
            // yyyy-mm(-dd) or common forms
            if (preg_match('/^\d{4}-(\d{2})(-\d{2})?/', $s)) return strtotime(substr($s, 0, 10));
            if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $s))   return strtotime($s);
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s))   return strtotime($s);
            if (preg_match('/^\d{4}$/', $s))                 return strtotime($s . '-01-01');
            return false;
        };

        // Decide if X is temporal, and bin if required
        $xLooksTemporal = false;
        foreach (array_slice($rows, 0, 20) as $r) {
            $v = $resolve($r, $x);
            if ($v !== null && ($isYear($v) || $parseTemporal($v) !== false)) {
                $xLooksTemporal = true;
                break;
            }
        }
        $xTemporalMode = $xLooksTemporal ? ($timeBin ?: 'auto') : null;

        // ---- aggregate matrix ----
        // Structure: [group][category] => {sum,count}
        $matrix = [];
        $allCats = [];
        $allGroups = [];

        foreach ($rows as $r) {
            $rawX = $resolve($r, $x);
            $rawG = $g ? $resolve($r, $g) : null;
            $rawY = $resolve($r, $y);

            // category label canonicalization
            $cat = $canon($rawX);

            // time binning
            if ($xTemporalMode) {
                if ($isYear($rawX)) {
                    $cat = (string)(int)$rawX; // year
                } else {
                    $ts = $parseTemporal($rawX);
                    if ($ts !== false) {
                        if ($xTemporalMode === 'year' || $xTemporalMode === 'auto') {
                            $cat = date('Y', $ts);
                        } elseif ($xTemporalMode === 'quarter') {
                            $cat = date('Y', $ts) . ' Q' . ceil((int)date('n', $ts) / 3);
                        } else { // month
                            $cat = date('Y-m', $ts);
                        }
                    }
                }
            }

            $grp = $g ? $canon($rawG) : '_single';
            $val = ($aggregate === 'count') ? 1.0 : (float)$rawY;

            if (!isset($matrix[$grp])) $matrix[$grp] = [];
            if (!isset($matrix[$grp][$cat])) $matrix[$grp][$cat] = ['sum' => 0.0, 'count' => 0];
            $matrix[$grp][$cat]['sum'] += $val;
            $matrix[$grp][$cat]['count']++;

            $allCats[$cat] = true;
            $allGroups[$grp] = true;
        }

        // compute aggregated value per cell
        $valueOf = function (array $cell) use ($aggregate) {
            return $aggregate === 'avg'
                ? ($cell['count'] ? $cell['sum'] / $cell['count'] : 0.0)
                : $cell['sum']; // sum or count were already encoded
        };

        // overall totals per category (used for sorting/topN)
        $catTotals = array_fill_keys(array_keys($allCats), 0.0);
        foreach ($matrix as $grp => $pairs) {
            foreach ($pairs as $cat => $cell) $catTotals[$cat] += $valueOf($cell);
        }

        // sort categories
        $categories = array_keys($catTotals);
        if ($xTemporalMode) {
            // natural temporal sort (year, year-month, year Qx)
            usort($categories, function ($a, $b) {
                // try timestamps
                $ta = strtotime(preg_replace('/\sQ\d$/', '-01', preg_replace('/^(\d{4})$/', '$1-01-01', $a)));
                $tb = strtotime(preg_replace('/\sQ\d$/', '-01', preg_replace('/^(\d{4})$/', '$1-01-01', $b)));
                return $ta <=> $tb;
            });
        } else {
            if ($sortBy === 'alpha') sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
            elseif ($sortBy === 'value_asc') asort($catTotals, SORT_NUMERIC);
            else arsort($catTotals, SORT_NUMERIC);
            if ($sortBy !== 'alpha') $categories = array_keys($catTotals);
        }

        // Top-N + "Other"
        $otherLabel = 'Other';
        if (count($categories) > $topN) {
            $top = array_slice($categories, 0, $topN);
            $categories = array_merge($top, [$otherLabel]);

            foreach ($matrix as $grp => $pairs) {
                $folded = array_fill_keys($categories, ['sum' => 0.0, 'count' => 0]);
                foreach ($pairs as $cat => $cell) {
                    $bucket = in_array($cat, $top, true) ? $cat : $otherLabel;
                    $folded[$bucket]['sum']   += $cell['sum'];
                    $folded[$bucket]['count'] += $cell['count'];
                }
                $matrix[$grp] = $folded;
            }
        } else {
            // ensure every category exists per group
            foreach ($matrix as $grp => $pairs) {
                foreach ($categories as $cat) {
                    if (!isset($matrix[$grp][$cat])) $matrix[$grp][$cat] = ['sum' => 0.0, 'count' => 0];
                }
            }
        }

        // normalization (percent or rate per K)
        if ($normalize === 'percent') {
            foreach ($matrix as $grp => $pairs) {
                $total = 0.0;
                foreach ($categories as $cat) $total += $valueOf($pairs[$cat]);
                $total = $total ?: 1.0;
                foreach ($categories as $cat) {
                    $val = $valueOf($pairs[$cat]) / $total * 100.0;
                    $matrix[$grp][$cat] = ['sum' => $val, 'count' => 1];
                }
            }
        } elseif ($normalize === 'rate_per_k') {
            $k = max(1, (int)$perK);
            foreach ($matrix as $grp => $pairs) {
                foreach ($categories as $cat) {
                    $val = $valueOf($pairs[$cat]) * (1000.0 / $k); // scale to per-k
                    $matrix[$grp][$cat] = ['sum' => $val, 'count' => 1];
                }
            }
        }

        // build ECharts structures
        $groups = array_keys($allGroups);
        if ($g) {
            $series = [];
            foreach ($groups as $grp) {
                $series[] = [
                    'name' => $grp,
                    'type' => $config['chart_type'],
                    'data' => array_map(fn($c) => $valueOf($matrix[$grp][$c]), $categories)
                ];
            }
            return ['categories' => $categories, 'series' => $series];
        }

        // single series
        $data = array_map(fn($c) => $valueOf($matrix['_single'][$c]), $categories);
        return ['categories' => $categories, 'values' => $data];
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
