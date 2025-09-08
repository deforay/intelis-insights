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
        
        $analysis = [
            'row_count' => count($rows),
            'column_count' => count($columns),
            'columns' => [],
            'sample_data' => array_slice($rows, 0, 3) // First 3 rows for LLM context
        ];

        foreach ($columns as $column) {
            $values = array_column($rows, $column);
            $uniqueValues = array_unique($values);
            
            $analysis['columns'][$column] = [
                'type' => $this->detectColumnType($values),
                'unique_count' => count($uniqueValues),
                'sample_values' => array_slice($uniqueValues, 0, 5),
                'all_values' => count($values) <= 20 ? $values : array_slice($values, 0, 20)
            ];
        }

        return $analysis;
    }

    private function getLLMChartSuggestions(array $analysis, string $intent, string $query): array
    {
        $prompt = $this->buildChartAnalysisPrompt($analysis, $intent, $query);
        
        try {
            $response = $this->llm->generateJson($prompt, 1000);
            $parsed = json_decode($response, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($parsed['suggestions'])) {
                return $this->processLLMSuggestions($parsed['suggestions']);
            }
        } catch (\Throwable $e) {
            error_log("Chart LLM analysis failed: " . $e->getMessage());
        }

        // Fallback to simple suggestions if LLM fails
        return $this->getFallbackSuggestions($analysis);
    }

    private function buildChartAnalysisPrompt(array $analysis, string $intent, string $query): string
    {
        $columnInfo = '';
        foreach ($analysis['columns'] as $column => $info) {
            $sampleValues = implode(', ', array_slice($info['sample_values'], 0, 3));
            $columnInfo .= "- {$column}: {$info['type']}, {$info['unique_count']} unique values, samples: {$sampleValues}\n";
        }

        $sampleDataJson = json_encode($analysis['sample_data'], JSON_PRETTY_PRINT);

        return <<<PROMPT
You are a medical data visualization expert. Analyze this laboratory database query result and suggest appropriate chart types.

QUERY: "{$query}"
DETECTED INTENT: {$intent}
ROW COUNT: {$analysis['row_count']}

COLUMNS:
{$columnInfo}

SAMPLE DATA (first 3 rows):
{$sampleDataJson}

Analyze this data and suggest appropriate chart types following these rules:

FOR THIS DATA PATTERN (facilities + counts + years):
1. PRIMARY suggestion should be GROUPED BAR CHART: facility_name (X-axis), total_tests (Y-axis), year (grouping)
2. SECONDARY suggestion can be PIE CHART: facility_name (labels), total_tests (values) 
3. DO NOT suggest line charts unless there's clear temporal progression
4. DO NOT suggest scatter plots for count data

CHART TYPE GUIDELINES:
- bar: Compare counts/values across categories (BEST for facility comparisons)
- pie: Show proportional distribution (OK for facility share)
- line: Only for time series or trends (NOT suitable here)
- scatter: Only for correlation analysis (NOT suitable here)

Respond with valid JSON only:
{
  "suggestions": [
    {
      "type": "bar",
      "title": "VL Tests by Facility (2024-2025)",
      "description": "Compare test volumes across facilities with year grouping",
      "x_axis": "facility_name",
      "y_axis": "total_tests", 
      "grouping": "year",
      "reasoning": "Best shows facility comparison with year breakdown"
    },
    {
      "type": "pie", 
      "title": "Test Distribution by Facility",
      "description": "Show proportional share of tests per facility",
      "x_axis": "facility_name",
      "y_axis": "total_tests",
      "reasoning": "Shows which facilities handle most tests"
    }
  ]
}

CRITICAL: For facility data, ALWAYS use facility_name as primary axis, never year.
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
        // Simple fallback when LLM fails
        $categoricalColumns = [];
        $numericColumns = [];

        foreach ($analysis['columns'] as $column => $info) {
            if ($info['type'] === 'string' && $info['unique_count'] <= 20) {
                $categoricalColumns[] = $column;
            }
            if (in_array($info['type'], ['integer', 'float'])) {
                $numericColumns[] = $column;
            }
        }

        if (!empty($categoricalColumns) && !empty($numericColumns)) {
            return [[
                'type' => 'bar',
                'title' => 'Bar Chart',
                'description' => 'Basic data visualization',
                'config' => $this->generateBarChartConfig($categoricalColumns[0], $numericColumns[0])
            ]];
        }

        return [];
    }

    private function generateBarChartConfig(string $xColumn, string $yColumn, ?string $groupingColumn = null): array
    {
        $config = [
            'chart_type' => 'bar',
            'x_axis' => $xColumn,
            'y_axis' => $yColumn,
            'grouping_column' => $groupingColumn,
            'echarts_option' => [
                'tooltip' => [
                    'trigger' => 'axis',
                    'axisPointer' => ['type' => 'shadow']
                ],
                'legend' => $groupingColumn ? ['top' => 'top'] : [],
                'xAxis' => [
                    'type' => 'category',
                    'name' => ucfirst(str_replace('_', ' ', $xColumn)),
                    'axisLabel' => ['interval' => 0, 'rotate' => 45]
                ],
                'yAxis' => [
                    'type' => 'value',
                    'name' => ucfirst(str_replace('_', ' ', $yColumn))
                ],
                'series' => [] // Will be populated with actual data
            ]
        ];

        return $config;
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
        $xColumn = $config['x_axis'];
        $yColumn = $config['y_axis'];
        $groupingColumn = $config['grouping_column'] ?? null;
        
        if ($groupingColumn) {
            // Group data by the grouping column (e.g., year)
            $groupedData = [];
            $categories = [];
            
            foreach ($rows as $row) {
                $category = $row[$xColumn];
                $group = $row[$groupingColumn];
                $value = (float)$row[$yColumn];
                
                if (!in_array($category, $categories)) {
                    $categories[] = $category;
                }
                
                if (!isset($groupedData[$group])) {
                    $groupedData[$group] = [];
                }
                $groupedData[$group][$category] = $value;
            }
            
            // Convert to ECharts series format
            $series = [];
            foreach ($groupedData as $groupName => $data) {
                $seriesData = [];
                foreach ($categories as $category) {
                    $seriesData[] = $data[$category] ?? 0;
                }
                $series[] = [
                    'name' => $groupName,
                    'type' => $config['chart_type'],
                    'data' => $seriesData
                ];
            }
            
            return [
                'categories' => $categories,
                'series' => $series
            ];
        } else {
            // Simple single series
            $categories = [];
            $values = [];
            
            foreach ($rows as $row) {
                $categories[] = $row[$xColumn];
                $values[] = (float)$row[$yColumn];
            }
            
            return [
                'categories' => $categories,
                'values' => $values
            ];
        }
    }

    private function formatPieData(array $rows, array $config): array
    {
        $labelColumn = $config['label_column'];
        $valueColumn = $config['value_column'];
        
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'name' => $row[$labelColumn],
                'value' => (float)$row[$valueColumn]
            ];
        }
        
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
        $firstValue = $values[0] ?? null;
        
        if (is_null($firstValue)) return 'null';
        if (is_int($firstValue)) return 'integer';
        if (is_float($firstValue)) return 'float';
        if (is_numeric($firstValue)) return 'numeric';
        if ($this->isTemporal([$firstValue])) return 'temporal';
        return 'string';
    }

    private function isTemporal(array $values): bool
    {
        foreach (array_slice($values, 0, 3) as $value) {
            if (!preg_match('/\d{4}-\d{2}-\d{2}/', (string)$value)) {
                return false;
            }
        }
        return true;
    }
}